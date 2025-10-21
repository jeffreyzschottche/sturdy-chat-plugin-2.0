<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SturdyChat_RAG
 * - Lexical prefilter (FULLTEXT met fallback naar LIKE)
 * - Zachte constraints (minstens 1 term/fraze of CPT-bypass)
 * - Vector re-ranking met cosine
 * - CPT- en datum-boosts
 * - Sitemap als standaard index
 */
class SturdyChat_RAG
{
    /**
     * Antwoordt op de vraag met uitsluitend context uit de index (closed-book).
     *
     * @return array{
     *   ok: bool,
     *   answer?: string,
     *   sources?: array<int, array{post_id:int,title:string,url:string,score:float}>,
     *   message?: string
     * }
     */
    public static function answer(string $question, array $s, array $hints = [], ?string $traceId = null): array
    {
        $topK = (int) ($s['top_k'] ?? 6);
        $temp = (float) ($s['temperature'] ?? 0.2);

        $retr = self::retrieve($question, $s, $topK, $hints, $traceId);
        $ctx  = trim((string) ($retr['context'] ?? ''));

        if ($ctx === '') {
            return [
                'ok'      => true,
                'answer'  => "Dit staat niet in de huidige kennisbank/context. Kun je het preciezer formuleren of andere trefwoorden proberen?",
                'sources' => [],
            ];
        }

        $base  = rtrim((string) ($s['openai_api_base'] ?? 'https://api.openai.com/v1'), '/');
        $key   = trim((string) ($s['openai_api_key'] ?? ''));
        $model = (string) ($s['chat_model'] ?? 'gpt-4o-mini');

        if ($key === '') {
            return ['ok' => false, 'message' => 'OpenAI API Key ontbreekt. Stel deze in.'];
        }

        $today = function_exists('wp_date') ? wp_date('Y-m-d') : date_i18n('Y-m-d');

        $sys = "Je antwoordt uitsluitend met feiten die letterlijk uit de CONTEXT-snippets blijken.
- Geen externe kennis of aannames.
- Als gevraagde info niet expliciet voorkomt, zeg: 'Dit staat niet in de huidige kennisbank/context.'
- Houd het kort en duidelijk in het Nederlands (2–4 zinnen).
- Noem geen namen/feiten die niet in de context staan.
- Datum van vandaag is: {$today}.";

        $messages = [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => "VRAAG:\n" . $question . "\n\nCONTEXT (snippets):\n" . $ctx],
        ];

        $res = wp_remote_post($base . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode([
                'model'       => $model,
                'temperature' => $temp,
                'messages'    => $messages,
            ]),
            'timeout' => 60,
        ]);

        if (is_wp_error($res)) {
            return ['ok' => false, 'message' => $res->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'message' => 'Chat request failed: HTTP ' . $code];
        }

        $body   = json_decode((string) wp_remote_retrieve_body($res), true);
        $answer = trim((string) ($body['choices'][0]['message']['content'] ?? ''));

        return [
            'ok'      => true,
            'answer'  => $answer,
            'sources' => $retr['sources'],
        ];
    }

    /**
     * Haalt relevante snippets op en bouwt context + bronnen.
     *
     * @return array{context:string,sources:array<int,array{post_id:int,title:string,url:string,score:float}>}
     */
    public static function retrieve(
        string $query,
        array $s,
        int $topK = 6,
        array $hints = [],
        ?string $traceId = null
    ): array {
        global $wpdb;

        // (1) Constraints & hints
        $cons        = self::buildConstraints($query);
        $mustTerms   = $cons['must_terms'];
        $numbers     = $cons['numbers'];
        $mustPhrases = $cons['must_phrases'];
        $antiTerms   = $cons['anti_terms'];

        $cptHint  = self::inferCptHint($query);
        $dateHint = self::parseDutchDate($query);

        // (2) Indexkeuze + CPT-routing (enkel sitemap)
        $primaryTable = STURDYCHAT_TABLE_SITEMAP;

        $candidates = [];

        if ($cptHint) {
            // HARD ROUTE: eerst alleen deze CPT ophalen
            $candidates = self::lexicalCandidatesByCpt($primaryTable, $cptHint, $query, 500);
        }

        if (!$candidates) {
            $candidates = self::lexicalCandidatesFromTable($primaryTable, $query);
        }

        if (!$candidates) {
            // allerlaatste reddingsboei: grof op CPT
            $fallback = self::lexicalCandidatesByCpt($primaryTable, $cptHint ?: 'nieuws', $query, 500);
            if ($fallback) {
                $candidates = array_merge($candidates, $fallback);
            }
        }

        if (!$candidates) {
            return ['context' => '', 'sources' => []];
        }
        // (3) Zachte filtering: anti-terms = hard exclude; musts = minstens 1 term/fraze
        // Bij CPT-match: mag must-checks overslaan (bypass).
        $filtered = [];
        foreach ($candidates as $r) {
            $c = mb_strtolower((string) ($r['content'] ?? '')); if ($c === '') continue;

            $rowCpt = mb_strtolower((string)($r['cpt'] ?? ''));
            $isCptMatch = ($cptHint && $rowCpt === $cptHint);

            // anti-terms → hard skip
            $bad = false;
            foreach ($antiTerms as $at) {
                $atL = mb_strtolower($at);
                if ($atL !== '' && mb_strpos($c, $atL) !== false) { $bad = true; break; }
            }
            if ($bad) continue;

            // musts: minstens 1 term / phrase. Bij CPT-match overslaan.
            $ok = true;
            if (!$isCptMatch) {
                $phrHit = empty($mustPhrases);
                if (!$phrHit) {
                    foreach ($mustPhrases as $ph) {
                        $phL = mb_strtolower($ph);
                        if ($phL !== '' && mb_strpos($c, $phL) !== false) { $phrHit = true; break; }
                    }
                }
                $termHit = empty($mustTerms);
                if (!$termHit) {
                    foreach ($mustTerms as $mt) {
                        if ($mt !== '' && mb_strpos($c, $mt) !== false) { $termHit = true; break; }
                    }
                }
                if (!$phrHit && !$termHit) $ok = false;
            }
            if (!$ok) continue;

            $r['bm25'] = self::normalizeBm25((float) ($r['bm_score'] ?? 0.0));
            $r['_cpt_match'] = $isCptMatch ? 1 : 0;

            // Hub-flag (bv. /partners/)
            $url = (string)($r['url'] ?? '');
            $r['_hub'] = ($url && $cptHint) ? (self::isHubUrl($url, $cptHint) ? 1 : 0) : 0;

            $filtered[] = $r;
        }
        if (!$filtered) return ['context' => '', 'sources' => []];


        // (4) Vector re-ranking + boosts
        $qvec = SturdyChat_Embedder::embed($query, $s);
        $nowTs      = function_exists('current_time') ? (int) current_time('timestamp') : time();
        $daySeconds = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;

        foreach ($filtered as &$row) {
            $e = json_decode((string) ($row['embedding'] ?? '[]'), true);
            $row['cos'] = ($e && is_array($e)) ? SturdyChat_Embedder::cosine($qvec, $e) : 0.0;

            $cov = self::coverageScore((string)$row['content'], $mustTerms, $mustPhrases);
            $num = self::numericOk((string)$row['content'], $numbers) ? 1.0 : 0.0;
            $bm  = (float) ($row['bm25'] ?? 0.0);

            $boost = 0.0;
            if (!empty($hints['postId'])) $boost += 0.05;
            if (!empty($hints['url']))    $boost += 0.05;

            // CPT-voorrang + hub super-boost
            if (!empty($row['_cpt_match'])) $boost += 0.50;
            if (!empty($row['_hub']))       $boost += 0.45;

            // Recency-boost: max +0.1 voor zeer recente content, lineair aflopend tot 0 na ~1 jaar
            $pubAt = trim((string) ($row['published_at'] ?? ''));
            if ($pubAt !== '') {
                $ts = strtotime($pubAt);
                if ($ts !== false && $ts > 0) {
                    $ageDays = max(0.0, ($nowTs - $ts) / max(1, $daySeconds));
                    if ($ageDays < 366.0) {
                        $freshFactor = max(0.0, 1.0 - ($ageDays / 365.0));
                        $boost += 0.1 * $freshFactor;
                    }
                }
            }

            // Datum-boost
            if (!empty($dateHint['iso']) || !empty($dateHint['text'])) {
                $hitDate  = false;
                $contentL = mb_strtolower((string)$row['content']);
                $urlL     = mb_strtolower((string)($row['url'] ?? ''));
                $pubAt    = (string)($row['published_at'] ?? '');
                if (!empty($dateHint['text']) && mb_strpos($contentL, $dateHint['text']) !== false) $hitDate = true;
                if (!empty($dateHint['iso']) && $pubAt && strpos($pubAt, $dateHint['iso']) === 0)    $hitDate = true;
                if ($hitDate) $boost += 0.15;
            }

            $row['final'] = (0.5 * $bm) + (0.4 * $row['cos']) + (0.1 * $cov) + (0.1 * $num) + $boost;
        }
        unset($row);

// Cosine-drempel — CPT of hub mag drempel negeren
        $cosMin = isset($s['cosine_min']) ? (float)$s['cosine_min'] : 0.18;
        $filtered = array_values(array_filter($filtered, static function (array $r) use ($cosMin): bool {
            if (!empty($r['_cpt_match']) || !empty($r['_hub'])) return true;
            return (($r['cos'] ?? 0.0) >= $cosMin);
        }));

        usort($filtered, static fn(array $a, array $b): int => ($a['final'] < $b['final']) ? 1 : -1);


        // (5) Groepeer per document, bouw context + sources
        $byDoc = [];
        foreach ($filtered as $r) {
            $key = ($r['_src'] ?? '') === 'sitemap'
                ? ('sm|' . md5((string) ($r['url'] ?? '') . '|' . (string) ($r['title'] ?? '')))
                : ('wp|' . (int) ($r['post_id'] ?? 0));
            $byDoc[$key][] = $r;
        }

        $ctxParts = [];
        $sources  = [];
        $picked   = 0;

        foreach ($byDoc as $rows) {
            if ($picked >= $topK) break;

            usort($rows, static fn(array $a, array $b): int => ($a['final'] < $b['final']) ? 1 : -1);
            $best  = array_slice($rows, 0, 2);
            $first = $best[0] ?? null;
            if (!$first) continue;

            if (($first['_src'] ?? '') === 'sitemap') {
                $url   = (string) ($first['url'] ?? '');
                $title = (string) ($first['title'] ?? $url);
                if ($url === '') continue;

                foreach ($best as $r) {
                    $snippet    = self::bestSnippet((string) $r['content'], $mustTerms, $mustPhrases, 650);
                    $ctxParts[] = '### ' . $title . ' — ' . $url . "\n" . $snippet;
                }

                $sources[] = [
                    'post_id' => 0,
                    'title'   => $title,
                    'url'     => $url,
                    'score'   => round((float) ($best[0]['final'] ?? 0.0), 4),
                ];
            } else {
                $pid  = (int) ($first['post_id'] ?? 0);
                $post = $pid ? get_post($pid) : null;
                if (!$post) continue;

                $url   = get_permalink($post);
                $title = get_the_title($post);

                foreach ($best as $r) {
                    $snippet    = self::bestSnippet((string) $r['content'], $mustTerms, $mustPhrases, 650);
                    $ctxParts[] = '### ' . $title . ' — ' . $url . "\n" . $snippet;
                }

                $sources[] = [
                    'post_id' => $pid,
                    'title'   => $title,
                    'url'     => $url,
                    'score'   => round((float) ($best[0]['final'] ?? 0.0), 4),
                ];
            }

            $picked++;
        }

        return [
            'context' => implode("\n\n---\n\n", $ctxParts),
            'sources' => $sources,
        ];
    }

    // ----------------------------
    // Lexical candidate fetchers
    // ----------------------------

    /**
     * Haal kandidaten op met FULLTEXT (title+content of content), met LIKE fallback.
     * Probeert automatisch title+content FT voor sitemap-tabel. Haalt ook cpt/published_at mee als kolommen bestaan.
     */
    protected static function lexicalCandidatesByCpt(string $table, string $cpt, string $query = '', int $limit = 400): array
    {
        global $wpdb;

        // Kolommen bepalen (veilig op schema-variaties)
        $cols = self::tableColumns($table);
        $isSitemap     = ($table === STURDYCHAT_TABLE_SITEMAP);
        $source        = $isSitemap ? 'sitemap' : 'wp';
        $hasTitle = isset($cols['title']);
        $hasUrl = isset($cols['url']);
        $hasCpt = isset($cols['cpt']);
        $hasPublishedAt = isset($cols['published_at']);
        $hasPostId = isset($cols['post_id']);

        if (!$hasCpt) return []; // zonder cpt-kolom kunnen we niet routeren

        $selUrl    = $hasUrl ? "url" : "'' AS url";
        $selTitle  = $hasTitle ? "title" : "'' AS title";
        $selPubAt  = $hasPublishedAt ? "published_at" : "NULL AS published_at";
        $selPostId = ($isSitemap || !$hasPostId) ? "0 AS post_id" : "post_id";

        // FULLTEXT detectie
        $ft = self::fulltextColumnsByIndex($table);
        $hasFTTitleContent = false; $hasFTContent = false;
        foreach ($ft as $colsFT) {
            $l = array_map('strtolower',$colsFT);
            if (in_array('title',$l,true) && in_array('content',$l,true)) $hasFTTitleContent = true;
            if (in_array('content',$l,true)) $hasFTContent = true;
        }

        // Wanneer de vraag “arm” is (“alle partners van dgbc”), wil je NIET hard filteren op MATCH,
        // maar wél cpt = 'partners' forceren en de vector later laten reranken.
        $useMatch = (mb_strlen(trim($query)) >= 3);

        if ($hasFTTitleContent && $useMatch) {
            $sql = $wpdb->prepare(
                "SELECT id, {$selPostId}, {$selUrl} AS url, {$selTitle} AS title, cpt, {$selPubAt} AS published_at,
                    chunk_index, content, embedding,
                    MATCH(title, content) AGAINST (%s IN NATURAL LANGUAGE MODE) AS bm_score
             FROM {$table}
             WHERE cpt = %s AND MATCH(title, content) AGAINST (%s IN NATURAL LANGUAGE MODE)
             ORDER BY bm_score DESC
             LIMIT %d",
                $query, $cpt, $query, $limit
            );
        } elseif ($hasFTContent && $useMatch) {
            $sql = $wpdb->prepare(
                "SELECT id, {$selPostId}, {$selUrl} AS url, {$selTitle} AS title, cpt, {$selPubAt} AS published_at,
                    chunk_index, content, embedding,
                    MATCH(content) AGAINST (%s IN NATURAL LANGUAGE MODE) AS bm_score
             FROM {$table}
             WHERE cpt = %s AND MATCH(content) AGAINST (%s IN NATURAL LANGUAGE MODE)
             ORDER BY bm_score DESC
             LIMIT %d",
                $query, $cpt, $query, $limit
            );
        } else {
            // LIKE fallback – brede recall, maar nog steeds cpt-gefilterd
            $like = '%' . $wpdb->esc_like($query) . '%';
            if ($useMatch && $hasTitle) {
                $sql = $wpdb->prepare(
                    "SELECT id, {$selPostId}, {$selUrl} AS url, {$selTitle} AS title, cpt, {$selPubAt} AS published_at,
                        chunk_index, content, embedding, 0 AS bm_score
                 FROM {$table}
                 WHERE cpt = %s AND (title LIKE %s OR content LIKE %s)
                 ORDER BY id DESC
                 LIMIT %d",
                    $cpt, $like, $like, $limit
                );
            } else {
                // puur op cpt, laat vector de rest doen
                $sql = $wpdb->prepare(
                    "SELECT id, {$selPostId}, {$selUrl} AS url, {$selTitle} AS title, cpt, {$selPubAt} AS published_at,
                        chunk_index, content, embedding, 0 AS bm_score
                 FROM {$table}
                 WHERE cpt = %s
                 ORDER BY id DESC
                 LIMIT %d",
                    $cpt, $limit
                );
            }
        }

        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
        foreach ($rows as &$r) { $r['_src'] = $source; }
        unset($r);
        return $rows;
    }

    protected static function lexicalCandidatesFromTable(string $table, string $query): array
    {
        global $wpdb;

        $isSitemap = ($table === STURDYCHAT_TABLE_SITEMAP);
        $source    = $isSitemap ? 'sitemap' : 'wp';

        // Bepaal kolommen die bestaan
        $cols = self::tableColumns($table);
        $hasTitle        = isset($cols['title']);
        $hasUrl          = isset($cols['url']);
        $hasCpt          = isset($cols['cpt']);
        $hasPublishedAt  = isset($cols['published_at']);
        $hasPostId       = isset($cols['post_id']);

        $selUrl    = $hasUrl   ? "url"                     : "'' AS url";
        $selTitle  = $hasTitle ? "title"                   : "'' AS title";
        $selCpt    = $hasCpt   ? "cpt"                     : "'' AS cpt";
        $selPubAt  = $hasPublishedAt ? "published_at"      : "NULL AS published_at";
        $selPostId = ($isSitemap || !$hasPostId) ? "0 AS post_id" : "post_id";

        // Detecteer FULLTEXT indexes
        $mode = 'LIKE'; // default fallback
        $ft   = self::fulltextColumnsByIndex($table); // ['keyname' => ['col1','col2',...]]
        $hasFTContent = false;
        $hasFTTitleContent = false;

        foreach ($ft as $key => $colset) {
            $colsLower = array_map('strtolower', $colset);
            if (in_array('content', $colsLower, true)) {
                $hasFTContent = true;
            }
            if ($hasTitle && in_array('title', $colsLower, true) && in_array('content', $colsLower, true)) {
                $hasFTTitleContent = true;
            }
        }

        if ($isSitemap && $hasFTTitleContent) {
            $mode = 'FT_TITLE_CONTENT';
        } elseif ($hasFTContent) {
            $mode = 'FT_CONTENT';
        }

        // Bouw SQL
        if ($mode === 'FT_TITLE_CONTENT') {
            $sql = $wpdb->prepare(
                "SELECT
                    id,
                    {$selPostId},
                    {$selUrl} AS url,
                    {$selTitle} AS title,
                    {$selCpt} AS cpt,
                    {$selPubAt} AS published_at,
                    chunk_index,
                    content,
                    embedding,
                    MATCH(title, content) AGAINST (%s IN NATURAL LANGUAGE MODE) AS bm_score
                 FROM {$table}
                 WHERE MATCH(title, content) AGAINST (%s IN NATURAL LANGUAGE MODE)
                 ORDER BY bm_score DESC
                 LIMIT 400",
                $query, $query
            );
        } elseif ($mode === 'FT_CONTENT') {
            $sql = $wpdb->prepare(
                "SELECT
                    id,
                    {$selPostId},
                    {$selUrl} AS url,
                    {$selTitle} AS title,
                    {$selCpt} AS cpt,
                    {$selPubAt} AS published_at,
                    chunk_index,
                    content,
                    embedding,
                    MATCH(content) AGAINST (%s IN NATURAL LANGUAGE MODE) AS bm_score
                 FROM {$table}
                 WHERE MATCH(content) AGAINST (%s IN NATURAL LANGUAGE MODE)
                 ORDER BY bm_score DESC
                 LIMIT 400",
                $query, $query
            );
        } else {
            // LIKE fallback – brede recall (title + content als title bestaat)
            $like = '%' . $wpdb->esc_like($query) . '%';
            if ($hasTitle) {
                $sql = $wpdb->prepare(
                    "SELECT
                        id,
                        {$selPostId},
                        {$selUrl} AS url,
                        {$selTitle} AS title,
                        {$selCpt} AS cpt,
                        {$selPubAt} AS published_at,
                        chunk_index,
                        content,
                        embedding,
                        0 AS bm_score
                     FROM {$table}
                     WHERE (title LIKE %s OR content LIKE %s)
                     ORDER BY id DESC
                     LIMIT 400",
                    $like, $like
                );
            } else {
                $sql = $wpdb->prepare(
                    "SELECT
                        id,
                        {$selPostId},
                        {$selUrl} AS url,
                        {$selTitle} AS title,
                        {$selCpt} AS cpt,
                        {$selPubAt} AS published_at,
                        chunk_index,
                        content,
                        embedding,
                        0 AS bm_score
                     FROM {$table}
                     WHERE content LIKE %s
                     ORDER BY id DESC
                     LIMIT 400",
                    $like
                );
            }
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$r) {
            $r['_src'] = $source;
        }
        unset($r);

        return $rows;
    }

    /**
     * Fallback ophalen als lexical niets gaf:
     * - Filter op CPT en/of datum waar mogelijk, anders pak laatste N.
     */
    protected static function lexicalCandidatesByCptDate(string $table, ?string $cptHint, array $dateHint, int $limit = 400): array
    {
        global $wpdb;

        $cols = self::tableColumns($table);
        $hasCpt         = isset($cols['cpt']);
        $hasPublishedAt = isset($cols['published_at']);
        $hasTitle       = isset($cols['title']);
        $hasUrl         = isset($cols['url']);
        $hasPostId      = isset($cols['post_id']);

        $selUrl    = $hasUrl   ? "url"                : "'' AS url";
        $selTitle  = $hasTitle ? "title"              : "'' AS title";
        $selCpt    = $hasCpt   ? "cpt"                : "'' AS cpt";
        $selPubAt  = $hasPublishedAt ? "published_at" : "NULL AS published_at";
        $selPostId = ($isSitemap || !$hasPostId) ? "0 AS post_id" : "post_id";

        $where = [];
        $params = [];

        if ($hasCpt && $cptHint) {
            $where[]  = "cpt = %s";
            $params[] = $cptHint;
        }
        if ($hasPublishedAt && !empty($dateHint['iso'])) {
            $where[]  = "published_at = %s";
            $params[] = $dateHint['iso'];
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = $wpdb->prepare(
            "SELECT
                id,
                {$selPostId},
                {$selUrl} AS url,
                {$selTitle} AS title,
                {$selCpt} AS cpt,
                {$selPubAt} AS published_at,
                chunk_index,
                content,
                embedding,
                0 AS bm_score
             FROM {$table}
             {$whereSql}
             ORDER BY id DESC
             LIMIT %d",
            ...array_merge($params, [$limit])
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$r) {
            $r['_src'] = $source;
        }
        unset($r);

        return $rows;
    }

    // ----------------------------
    // Helpers
    // ----------------------------

    /**
     * Bepaalt aanwezige kolommen van een tabel (cache per-request).
     * @return array<string, true>
     */
    private static function tableColumns(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];

        global $wpdb;
        $cols = [];
        $res = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        if (is_array($res)) {
            foreach ($res as $r) {
                $name = strtolower((string) ($r['Field'] ?? ''));
                if ($name !== '') $cols[$name] = true;
            }
        }
        $cache[$table] = $cols;
        return $cols;
    }

    /**
     * Fulltext index → lijst van columns per index key.
     * @return array<string, string[]>
     */
    private static function fulltextColumnsByIndex(string $table): array
    {
        global $wpdb;
        $res = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A) ?: [];
        $byKey = [];
        foreach ($res as $row) {
            $type = strtolower((string) ($row['Index_type'] ?? ''));
            if ($type !== 'fulltext') continue;
            $key  = (string) ($row['Key_name'] ?? '');
            $col  = (string) ($row['Column_name'] ?? '');
            if ($key !== '' && $col !== '') {
                $byKey[$key][] = $col;
            }
        }
        return $byKey;
    }

    /**
     * Constraints uit de vraag.
     * @return array{must_terms:string[],must_phrases:string[],numbers:string[],anti_terms:string[]}
     */
    private static function buildConstraints(string $q): array
    {
        $t = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $q)));

        $tokens = preg_split('/[^\p{L}\p{N}\-]+/u', $t, -1, PREG_SPLIT_NO_EMPTY);

        // NL stopwoorden (kort)
        $stop = [
            'de','het','een','en','of','in','op','te','van','voor','met','zonder',
            'dat','die','dit','is','zijn','wie','wat','waar','wanneer','hoe','heeft','heb',
            'zijn','er','om','te','naar','bij','dan','als','maar'
        ];

        $must = array_values(array_filter(
            $tokens,
            static fn(string $w): bool => (mb_strlen($w) >= 3) && !in_array($w, $stop, true)
        ));

        preg_match_all('/"([^"]+)"/u', $q, $mQuotes);
        $phrases = array_map('trim', $mQuotes[1] ?? []);

        preg_match_all('/(?:€\s*)?\d[\d\.\,]*/u', $q, $mNums);
        $numbers = array_map('trim', $mNums[0] ?? []);

        $anti = []; // optioneel vullen als je ongewenste termen wilt uitsluiten

        return [
            'must_terms'   => array_values(array_unique($must)),
            'must_phrases' => $phrases,
            'numbers'      => $numbers,
            'anti_terms'   => $anti,
        ];
    }

    /**
     * Herken CPT-hints uit de vraag (bv. "interview(s)").
     */
    private static function inferCptHint(string $q): ?string
    {
        $t = mb_strtolower($q);
        // expliciete mapping
        $map = [
            'partner' => 'partners',
            'partners' => 'partners',
            'kenniscentrum' => 'kenniscentrum',
            'kennisbank' => 'kenniscentrum',
            'knowledge center' => 'kenniscentrum',
            'interview' => 'interviews',
            'interviews' => 'interviews',
            'nieuws' => 'nieuws',
            'news' => 'nieuws',
            'agenda' => 'agenda',
            'evenement' => 'agenda',
            'evenementen' => 'agenda',
            'magazine' => 'magazine',
            'case' => 'cases',
            'cases' => 'cases',
            'project' => 'projecten',
            'projecten' => 'projecten',
            'vacature' => 'vacatures',
            'vacatures' => 'vacatures',
        ];
        foreach ($map as $needle => $cpt) {
            if (mb_strpos($t, $needle) !== false) return $cpt;
        }
        // hint via “/partners/” etc.
        if (preg_match('~/([a-z0-9\-]+)/~', $t, $m)) {
            return $m[1];
        }
        return null;
    }

    private static function isHubUrl(string $url, string $cpt): bool
    {
        $p = parse_url($url, PHP_URL_PATH) ?? '';
        $p = rtrim($p, '/');
        $c = '/'.trim($cpt,'/');
        return $p === $c || $p === $c.'/index';
    }


    /**
     * Parse NL-datum uit de vraag (bv. "10 september 2025").
     * @return array{iso:string,text:string}
     */
    private static function parseDutchDate(string $q): array
    {
        $months = ['januari'=>1,'februari'=>2,'maart'=>3,'april'=>4,'mei'=>5,'juni'=>6,'juli'=>7,'augustus'=>8,'september'=>9,'oktober'=>10,'november'=>11,'december'=>12];
        $t = mb_strtolower($q);
        if (preg_match('/\b(\d{1,2})\s+(januari|februari|maart|april|mei|juni|juli|augustus|september|oktober|november|december)\s+(\d{4})\b/u', $t, $m)) {
            $d=(int)$m[1]; $mo=(int)$months[$m[2]]; $y=(int)$m[3];
            if ($mo>0) return ['iso'=>sprintf('%04d-%02d-%02d',$y,$mo,$d),'text'=>"$d {$m[2]} $y"];
        }
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $q, $m)) {
            $iso = $m[0];
            $months2=[1=>'januari',2=>'februari',3=>'maart',4=>'april',5=>'mei',6=>'juni',7=>'juli',8=>'augustus',9=>'september',10=>'oktober',11=>'november',12=>'december'];
            $text = ltrim((string)((int)$m[3]),'0').' '.($months2[(int)$m[2]]??$m[2]).' '.$m[1];
            return ['iso'=>$iso,'text'=>mb_strtolower($text)];
        }
        return ['iso'=>'','text'=>''];
    }

    /**
     * Normaliseer BM-score grofweg naar [0..1].
     */
    private static function normalizeBm25(float $v): float
    {
        return max(0.0, min(1.0, 1.0 / (1.0 + exp(-0.5 * ($v - 5.0)))));
    }

    /**
     * Coverage van must-terms/phrases [0..1].
     */
    private static function coverageScore(string $text, array $mustTerms, array $mustPhrases): float
    {
        $t    = mb_strtolower($text);
        $hits = 0;
        $need = 0;

        foreach ($mustTerms as $mt) {
            if ($mt === '') continue;
            $need++;
            if (mb_strpos($t, $mt) !== false) $hits++;
        }
        foreach ($mustPhrases as $ph) {
            $phL = mb_strtolower($ph);
            if ($phL === '') continue;
            $need++;
            if (mb_strpos($t, $phL) !== false) $hits++;
        }
        if ($need === 0) return 1.0;
        return $hits / $need;
    }

    /**
     * Checkt of alle numerieke patronen uit de vraag terugkomen (soepel).
     */
    private static function numericOk(string $text, array $numbers): bool
    {
        if (empty($numbers)) return true;
        $t            = mb_strtolower($text);
        $digitsInText = preg_replace('/[^\d]/', '', $t);
        foreach ($numbers as $n) {
            $nNorm = preg_replace('/[^\d]/', '', $n);
            if ($nNorm === '') continue;
            if ($digitsInText === '' || mb_strpos((string)$digitsInText, (string)$nNorm) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Snippet rond eerste matchende term/fraze.
     */
    private static function bestSnippet(string $content, array $mustTerms, array $mustPhrases, int $size = 650): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', $content));
        if (mb_strlen($t) <= $size) return $t;

        $needles = array_merge($mustTerms, array_map('mb_strtolower', $mustPhrases));
        $pos     = PHP_INT_MAX;

        foreach ($needles as $n) {
            if ($n === '') continue;
            $p = mb_stripos($t, $n);
            if ($p !== false && $p < $pos) $pos = (int) $p;
        }
        if ($pos === PHP_INT_MAX) $pos = 0;

        $start   = max(0, $pos - (int) ($size / 3));
        $snippet = mb_substr($t, $start, $size);

        return ($start > 0 ? '…' : '') . $snippet . (mb_strlen($t) > $start + $size ? '…' : '');
    }
}
