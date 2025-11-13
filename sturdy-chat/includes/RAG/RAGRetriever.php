<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class SturdyChat_RAG_Retriever
{
    /**
     * @return array{context:string,sources:array<int,array{post_id:int,title:string,url:string,score:float}>}
     */
    public static function retrieve(
        string $query,
        array $settings,
        int $topK = 6,
        array $hints = [],
        ?string $traceId = null
    ): array {
        unset($traceId); // not used yet, placeholder for future tracing integration.

        global $wpdb;

        $constraints     = self::buildConstraints($query);
        $mustTerms       = $constraints['must_terms'];
        $numbers         = $constraints['numbers'];
        $mustPhrases     = $constraints['must_phrases'];
        $antiTerms       = $constraints['anti_terms'];
        $cptHint         = self::inferCptHint($query);
        $queryLower      = mb_strtolower($query);
        $dateHint        = self::parseDutchDate($query);
        $cptPriority     = self::buildCptPriorityBoosts($settings);
        $primaryTable    = STURDYCHAT_TABLE_SITEMAP;

        $candidates = [];

        if ($cptHint) {
            $candidates = self::lexicalCandidatesByCpt($primaryTable, $cptHint, $query, 500);
        }

        if (!$candidates) {
            $candidates = self::lexicalCandidatesFromTable($primaryTable, $query);
        }

        if (!$candidates) {
            $fallback = self::lexicalCandidatesByCpt($primaryTable, $cptHint ?: 'nieuws', $query, 500);
            if ($fallback) {
                $candidates = array_merge($candidates, $fallback);
            }
        }

        if (!$candidates) {
            return ['context' => '', 'sources' => []];
        }

        $filtered = [];
        foreach ($candidates as $row) {
            $content = mb_strtolower((string) ($row['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $rowCpt      = mb_strtolower((string) ($row['cpt'] ?? ''));
            $isCptMatch  = ($cptHint && $rowCpt === $cptHint);

            $hasAntiTerm = false;
            foreach ($antiTerms as $anti) {
                $needle = mb_strtolower($anti);
                if ($needle !== '' && mb_strpos($content, $needle) !== false) {
                    $hasAntiTerm = true;
                    break;
                }
            }
            if ($hasAntiTerm) {
                continue;
            }

            $passesMust = true;
            if (!$isCptMatch) {
                $phraseHit = empty($mustPhrases);
                if (!$phraseHit) {
                    foreach ($mustPhrases as $phrase) {
                        $needle = mb_strtolower($phrase);
                        if ($needle !== '' && mb_strpos($content, $needle) !== false) {
                            $phraseHit = true;
                            break;
                        }
                    }
                }

                $termHit = empty($mustTerms);
                if (!$termHit) {
                    foreach ($mustTerms as $term) {
                        if ($term !== '' && mb_strpos($content, $term) !== false) {
                            $termHit = true;
                            break;
                        }
                    }
                }

                if (!$phraseHit && !$termHit) {
                    $passesMust = false;
                }
            }

            if (!$passesMust) {
                continue;
            }

            $row['bm25']        = self::normalizeBm25((float) ($row['bm_score'] ?? 0.0));
            $row['_cpt_match']  = $isCptMatch ? 1 : 0;
            $url                = (string) ($row['url'] ?? '');
            $row['_hub']        = ($url && $cptHint) ? (self::isHubUrl($url, $cptHint) ? 1 : 0) : 0;

            $filtered[] = $row;
        }

        if (!$filtered) {
            return ['context' => '', 'sources' => []];
        }

        $queryVector = SturdyChat_Embedder::embed($query, $settings);

        foreach ($filtered as &$row) {
            $embedding = json_decode((string) ($row['embedding'] ?? '[]'), true);
            $row['cos'] = ($embedding && is_array($embedding)) ? SturdyChat_Embedder::cosine($queryVector, $embedding) : 0.0;

            $coverage  = self::coverageScore((string) $row['content'], $mustTerms, $mustPhrases);
            $numeric   = self::numericOk((string) $row['content'], $numbers) ? 1.0 : 0.0;
            $bm25      = (float) ($row['bm25'] ?? 0.0);
            $boost     = 0.0;

            if (!empty($hints['postId'])) {
                $boost += 0.05;
            }
            if (!empty($hints['url'])) {
                $boost += 0.05;
            }

            $rowCpt = sanitize_key((string) ($row['cpt'] ?? ''));
            if ($rowCpt !== '' && isset($cptPriority[$rowCpt])) {
                $boost += $cptPriority[$rowCpt];
            }

            if (!empty($row['_cpt_match'])) {
                $boost += 0.50;
            }
            if (!empty($row['_hub'])) {
                $boost += 0.45;
            }

            $title     = mb_strtolower((string) ($row['title'] ?? ''));
            $titleBoost = 0.0;
            if ($title !== '') {
                $titleHits = 0;
                $titleNeed = 0;
                $seen      = [];

                foreach ($mustTerms as $term) {
                    if ($term === '') {
                        continue;
                    }
                    if (isset($seen[$term])) {
                        continue;
                    }
                    $titleNeed++;
                    if (mb_strpos($title, $term) !== false) {
                        $titleHits++;
                    }
                    $seen[$term] = true;
                }

                foreach ($mustPhrases as $phrase) {
                    $needle = mb_strtolower($phrase);
                    if ($needle === '') {
                        continue;
                    }
                    if (isset($seen[$needle])) {
                        continue;
                    }
                    $titleNeed++;
                    if (mb_strpos($title, $needle) !== false) {
                        $titleHits++;
                    }
                    $seen[$needle] = true;
                }

                if ($titleNeed > 0 && $titleHits > 0) {
                    $titleBoost = 0.35 * ($titleHits / $titleNeed);
                }
            }
            $boost += $titleBoost;

            if ($title !== '' && function_exists('similar_text')) {
                $titleSimPct = 0.0;
                similar_text($title, $queryLower, $titleSimPct);
                if ($titleSimPct > 0) {
                    $boost += min(0.4, ($titleSimPct / 100) * 0.4);
                }
            }

            if (!empty($dateHint['iso']) || !empty($dateHint['text'])) {
                $hitDate  = false;
                $contentL = mb_strtolower((string) $row['content']);
                $pubAt    = (string) ($row['published_at'] ?? '');

                if (!empty($dateHint['text']) && mb_strpos($contentL, $dateHint['text']) !== false) {
                    $hitDate = true;
                }
                if (!empty($dateHint['iso']) && $pubAt && strpos($pubAt, $dateHint['iso']) === 0) {
                    $hitDate = true;
                }

                if ($hitDate) {
                    $boost += 0.15;
                }
            }

            $row['final'] = (0.5 * $bm25) + (0.4 * $row['cos']) + (0.1 * $coverage) + (0.1 * $numeric) + $boost;
        }
        unset($row);

        $cosMin = isset($settings['cosine_min']) ? (float) $settings['cosine_min'] : 0.18;
        $filtered = array_values(array_filter($filtered, static function (array $row) use ($cosMin): bool {
            if (!empty($row['_cpt_match']) || !empty($row['_hub'])) {
                return true;
            }
            return (($row['cos'] ?? 0.0) >= $cosMin);
        }));

        usort($filtered, static fn(array $a, array $b): int => ($a['final'] < $b['final']) ? 1 : -1);

        $documents = [];
        foreach ($filtered as $row) {
            $key = ($row['_src'] ?? '') === 'sitemap'
                ? ('sm|' . md5((string) ($row['url'] ?? '') . '|' . (string) ($row['title'] ?? '')))
                : ('wp|' . (int) ($row['post_id'] ?? 0));
            $documents[$key][] = $row;
        }

        $contextParts = [];
        $sources      = [];
        $picked       = 0;

        foreach ($documents as $rows) {
            if ($picked >= $topK) {
                break;
            }

            usort($rows, static fn(array $a, array $b): int => ($a['final'] < $b['final']) ? 1 : -1);
            $best  = array_slice($rows, 0, 2);
            $first = $best[0] ?? null;
            if (!$first) {
                continue;
            }

            if (($first['_src'] ?? '') === 'sitemap') {
                $url   = (string) ($first['url'] ?? '');
                $title = (string) ($first['title'] ?? $url);
                if ($url === '') {
                    continue;
                }

                foreach ($best as $row) {
                    $snippet      = self::bestSnippet((string) $row['content'], $mustTerms, $mustPhrases, 650);
                    $contextParts[] = '### ' . $title . ' — ' . $url . "\n" . $snippet;
                }

                $sources[] = [
                    'post_id' => 0,
                    'title'   => $title,
                    'url'     => $url,
                    'score'   => round((float) ($best[0]['final'] ?? 0.0), 4),
                ];
            } else {
                $postId = (int) ($first['post_id'] ?? 0);
                $post   = $postId ? get_post($postId) : null;
                if (!$post) {
                    continue;
                }

                $url   = get_permalink($post);
                $title = get_the_title($post);

                foreach ($best as $row) {
                    $snippet      = self::bestSnippet((string) $row['content'], $mustTerms, $mustPhrases, 650);
                    $contextParts[] = '### ' . $title . ' — ' . $url . "\n" . $snippet;
                }

                $sources[] = [
                    'post_id' => $postId,
                    'title'   => $title,
                    'url'     => $url,
                    'score'   => round((float) ($best[0]['final'] ?? 0.0), 4),
                ];
            }

            $picked++;
        }

        return [
            'context' => implode("\n\n---\n\n", $contextParts),
            'sources' => $sources,
        ];
    }

    /** @return array{must_terms:string[],must_phrases:string[],numbers:string[],anti_terms:string[]} */
    private static function buildConstraints(string $question): array
    {
        $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $question)));

        $tokens = preg_split('/[^\p{L}\p{N}\-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $stopwords = [
            'de','het','een','en','of','in','op','te','van','voor','met','zonder',
            'dat','die','dit','is','zijn','wie','wat','waar','wanneer','hoe','heeft','heb',
            'zijn','er','om','naar','bij','dan','als','maar'
        ];

        $must = array_values(array_filter(
            $tokens,
            static fn(string $word): bool => (mb_strlen($word) >= 3) && !in_array($word, $stopwords, true)
        ));

        preg_match_all('/"([^"]+)"/u', $question, $phraseMatches);
        $phrases = array_map('trim', $phraseMatches[1] ?? []);

        preg_match_all('/(?:€\s*)?\d[\d\.\,]*/u', $question, $numberMatches);
        $numbers = array_map('trim', $numberMatches[0] ?? []);

        return [
            'must_terms'   => array_values(array_unique($must)),
            'must_phrases' => $phrases,
            'numbers'      => $numbers,
            'anti_terms'   => [],
        ];
    }

    private static function inferCptHint(string $question): ?string
    {
        $text = mb_strtolower($question);
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
            if (mb_strpos($text, $needle) !== false) {
                return $cpt;
            }
        }

        if (preg_match('~/([a-z0-9\-]+)/~', $text, $match)) {
            return $match[1];
        }

        return null;
    }

    private static function parseDutchDate(string $question): array
    {
        $months = [
            'januari'=>1,'februari'=>2,'maart'=>3,'april'=>4,'mei'=>5,'juni'=>6,
            'juli'=>7,'augustus'=>8,'september'=>9,'oktober'=>10,'november'=>11,'december'=>12
        ];
        $text = mb_strtolower($question);
        if (preg_match('/\b(\d{1,2})\s+(januari|februari|maart|april|mei|juni|juli|augustus|september|oktober|november|december)\s+(\d{4})\b/u', $text, $match)) {
            $day = (int) $match[1];
            $month = (int) $months[$match[2]];
            $year = (int) $match[3];
            if ($month > 0) {
                return [
                    'iso'  => sprintf('%04d-%02d-%02d', $year, $month, $day),
                    'text' => "$day {$match[2]} $year",
                ];
            }
        }
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $question, $match)) {
            $iso = $match[0];
            $monthNames = [
                1=>'januari',2=>'februari',3=>'maart',4=>'april',5=>'mei',6=>'juni',
                7=>'juli',8=>'augustus',9=>'september',10=>'oktober',11=>'november',12=>'december'
            ];
            $textual = ltrim((string) ((int) $match[3]), '0') . ' ' . ($monthNames[(int) $match[2]] ?? $match[2]) . ' ' . $match[1];
            return ['iso' => $iso, 'text' => mb_strtolower($textual)];
        }

        return ['iso' => '', 'text' => ''];
    }

    private static function lexicalCandidatesByCpt(string $table, string $cpt, string $query = '', int $limit = 400): array
    {
        global $wpdb;

        $columns = self::tableColumns($table);
        $isSitemap = ($table === STURDYCHAT_TABLE_SITEMAP);
        $source    = $isSitemap ? 'sitemap' : 'wp';
        $hasTitle  = isset($columns['title']);
        $hasUrl    = isset($columns['url']);
        $hasCpt    = isset($columns['cpt']);
        $hasPublishedAt = isset($columns['published_at']);
        $hasPostId = isset($columns['post_id']);

        if (!$hasCpt) {
            return [];
        }

        $selectUrl    = $hasUrl ? "url" : "'' AS url";
        $selectTitle  = $hasTitle ? "title" : "'' AS title";
        $selectPubAt  = $hasPublishedAt ? "published_at" : "NULL AS published_at";
        $selectPostId = ($isSitemap || !$hasPostId) ? "0 AS post_id" : "post_id";

        $fulltext = self::fulltextColumnsByIndex($table);
        $hasFTTitleContent = false;
        $hasFTContent      = false;
        foreach ($fulltext as $columnsSet) {
            $lower = array_map('strtolower', $columnsSet);
            if (in_array('title', $lower, true) && in_array('content', $lower, true)) {
                $hasFTTitleContent = true;
            }
            if (in_array('content', $lower, true)) {
                $hasFTContent = true;
            }
        }

        $useMatch = (mb_strlen(trim($query)) >= 3);

        if ($hasFTTitleContent && $useMatch) {
            $sql = $wpdb->prepare(
                "SELECT id, {$selectPostId}, {$selectUrl} AS url, {$selectTitle} AS title, cpt, {$selectPubAt} AS published_at,
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
                "SELECT id, {$selectPostId}, {$selectUrl} AS url, {$selectTitle} AS title, cpt, {$selectPubAt} AS published_at,
                        chunk_index, content, embedding,
                        MATCH(content) AGAINST (%s IN NATURAL LANGUAGE MODE) AS bm_score
                 FROM {$table}
                 WHERE cpt = %s AND MATCH(content) AGAINST (%s IN NATURAL LANGUAGE MODE)
                 ORDER BY bm_score DESC
                 LIMIT %d",
                $query, $cpt, $query, $limit
            );
        } else {
            $like = '%' . $wpdb->esc_like($query) . '%';
            if ($useMatch && $hasTitle) {
                $sql = $wpdb->prepare(
                    "SELECT id, {$selectPostId}, {$selectUrl} AS url, {$selectTitle} AS title, cpt, {$selectPubAt} AS published_at,
                            chunk_index, content, embedding, 0 AS bm_score
                     FROM {$table}
                     WHERE cpt = %s AND (title LIKE %s OR content LIKE %s)
                     ORDER BY id DESC
                     LIMIT %d",
                    $cpt, $like, $like, $limit
                );
            } else {
                $sql = $wpdb->prepare(
                    "SELECT id, {$selectPostId}, {$selectUrl} AS url, {$selectTitle} AS title, cpt, {$selectPubAt} AS published_at,
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
        foreach ($rows as &$row) {
            $row['_src'] = $source;
        }
        unset($row);

        return $rows;
    }

    private static function lexicalCandidatesFromTable(string $table, string $query): array
    {
        global $wpdb;

        $isSitemap = ($table === STURDYCHAT_TABLE_SITEMAP);
        $source    = $isSitemap ? 'sitemap' : 'wp';

        $columns = self::tableColumns($table);
        $hasTitle       = isset($columns['title']);
        $hasUrl         = isset($columns['url']);
        $hasCpt         = isset($columns['cpt']);
        $hasPublishedAt = isset($columns['published_at']);
        $hasPostId      = isset($columns['post_id']);

        $selectUrl    = $hasUrl ? "url" : "'' AS url";
        $selectTitle  = $hasTitle ? "title" : "'' AS title";
        $selectCpt    = $hasCpt ? "cpt" : "'' AS cpt";
        $selectPubAt  = $hasPublishedAt ? "published_at" : "NULL AS published_at";
        $selectPostId = ($isSitemap || !$hasPostId) ? "0 AS post_id" : "post_id";

        $mode = 'LIKE';
        $fulltext = self::fulltextColumnsByIndex($table);
        $hasFTContent = false;
        $hasFTTitleContent = false;

        foreach ($fulltext as $columnsSet) {
            $lower = array_map('strtolower', $columnsSet);
            if (in_array('content', $lower, true)) {
                $hasFTContent = true;
            }
            if ($hasTitle && in_array('title', $lower, true) && in_array('content', $lower, true)) {
                $hasFTTitleContent = true;
            }
        }

        if ($isSitemap && $hasFTTitleContent) {
            $mode = 'FT_TITLE_CONTENT';
        } elseif ($hasFTContent) {
            $mode = 'FT_CONTENT';
        }

        if ($mode === 'FT_TITLE_CONTENT') {
            $sql = $wpdb->prepare(
                "SELECT id, {$selectPostId}, {$selectUrl} AS url, {$selectTitle} AS title, {$selectCpt} AS cpt,
                        {$selectPubAt} AS published_at, chunk_index, content, embedding,
                        MATCH(title, content) AGAINST (%s IN NATURAL LANGUAGE MODE) AS bm_score
                 FROM {$table}
                 WHERE MATCH(title, content) AGAINST (%s IN NATURAL LANGUAGE MODE)
                 ORDER BY bm_score DESC
                 LIMIT 400",
                $query, $query
            );
        } elseif ($mode === 'FT_CONTENT') {
            $sql = $wpdb->prepare(
                "SELECT id, {$selectPostId}, {$selectUrl} AS url, {$selectTitle} AS title, {$selectCpt} AS cpt,
                        {$selectPubAt} AS published_at, chunk_index, content, embedding,
                        MATCH(content) AGAINST (%s IN NATURAL LANGUAGE MODE) AS bm_score
                 FROM {$table}
                 WHERE MATCH(content) AGAINST (%s IN NATURAL LANGUAGE MODE)
                 ORDER BY bm_score DESC
                 LIMIT 400",
                $query, $query
            );
        } else {
            $like = '%' . $wpdb->esc_like($query) . '%';
            if ($hasTitle) {
                $sql = $wpdb->prepare(
                    "SELECT id, {$selectPostId}, {$selectUrl} AS url, {$selectTitle} AS title, {$selectCpt} AS cpt,
                            {$selectPubAt} AS published_at, chunk_index, content, embedding, 0 AS bm_score
                     FROM {$table}
                     WHERE (title LIKE %s OR content LIKE %s)
                     ORDER BY id DESC
                     LIMIT 400",
                    $like, $like
                );
            } else {
                $sql = $wpdb->prepare(
                    "SELECT id, {$selectPostId}, {$selectUrl} AS url, {$selectTitle} AS title, {$selectCpt} AS cpt,
                            {$selectPubAt} AS published_at, chunk_index, content, embedding, 0 AS bm_score
                     FROM {$table}
                     WHERE content LIKE %s
                     ORDER BY id DESC
                     LIMIT 400",
                    $like
                );
            }
        }

        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
        foreach ($rows as &$row) {
            $row['_src'] = $source;
        }
        unset($row);

        return $rows;
    }

    private static function lexicalCandidatesByCptDate(string $table, ?string $cptHint, array $dateHint, int $limit = 400): array
    {
        global $wpdb;

        $columns = self::tableColumns($table);
        $isSitemap = ($table === STURDYCHAT_TABLE_SITEMAP);
        $source    = $isSitemap ? 'sitemap' : 'wp';
        $hasCpt    = isset($columns['cpt']);
        $hasPublishedAt = isset($columns['published_at']);
        $hasTitle  = isset($columns['title']);
        $hasUrl    = isset($columns['url']);
        $hasPostId = isset($columns['post_id']);

        $selectUrl    = $hasUrl ? "url" : "'' AS url";
        $selectTitle  = $hasTitle ? "title" : "'' AS title";
        $selectCpt    = $hasCpt ? "cpt" : "'' AS cpt";
        $selectPubAt  = $hasPublishedAt ? "published_at" : "NULL AS published_at";
        $selectPostId = ($isSitemap || !$hasPostId) ? "0 AS post_id" : "post_id";

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
            "SELECT id, {$selectPostId}, {$selectUrl} AS url, {$selectTitle} AS title, {$selectCpt} AS cpt,
                    {$selectPubAt} AS published_at, chunk_index, content, embedding, 0 AS bm_score
             FROM {$table}
             {$whereSql}
             ORDER BY id DESC
             LIMIT %d",
            ...array_merge($params, [$limit])
        );

        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
        foreach ($rows as &$row) {
            $row['_src'] = $source;
        }
        unset($row);

        return $rows;
    }

    private static function buildCptPriorityBoosts(array $settings): array
    {
        $orderList = [];
        if (isset($settings['index_post_types_order']) && is_array($settings['index_post_types_order'])) {
            foreach ($settings['index_post_types_order'] as $slug) {
                $slug = sanitize_key((string) $slug);
                if ($slug === '') {
                    continue;
                }
                if (!in_array($slug, $orderList, true)) {
                    $orderList[] = $slug;
                }
            }
        }

        if (!$orderList && function_exists('sturdychat_all_public_types')) {
            foreach (sturdychat_all_public_types() as $slug) {
                $slug = sanitize_key((string) $slug);
                if ($slug === '') {
                    continue;
                }
                if (!in_array($slug, $orderList, true)) {
                    $orderList[] = $slug;
                }
            }
        }

        $total = count($orderList);
        if ($total === 0) {
            return [];
        }

        $maxBoost = 0.12;
        $minBoost = -0.02;
        $step     = $total > 1 ? ($maxBoost - $minBoost) / ($total - 1) : 0.0;

        $map = [];
        foreach ($orderList as $index => $slug) {
            $boost = $maxBoost - ($index * $step);
            if ($boost < $minBoost) {
                $boost = $minBoost;
            }
            $map[$slug] = round($boost, 6);
        }

        return $map;
    }

    private static function normalizeBm25(float $value): float
    {
        return max(0.0, min(1.0, 1.0 / (1.0 + exp(-0.5 * ($value - 5.0)))));
    }

    private static function coverageScore(string $text, array $mustTerms, array $mustPhrases): float
    {
        $lower = mb_strtolower($text);
        $hits  = 0;
        $need  = 0;

        foreach ($mustTerms as $term) {
            if ($term === '') {
                continue;
            }
            $need++;
            if (mb_strpos($lower, $term) !== false) {
                $hits++;
            }
        }
        foreach ($mustPhrases as $phrase) {
            $needle = mb_strtolower($phrase);
            if ($needle === '') {
                continue;
            }
            $need++;
            if (mb_strpos($lower, $needle) !== false) {
                $hits++;
            }
        }

        if ($need === 0) {
            return 1.0;
        }

        return $hits / $need;
    }

    private static function numericOk(string $text, array $numbers): bool
    {
        if (empty($numbers)) {
            return true;
        }
        $digitsInText = preg_replace('/[^\d]/', '', mb_strtolower($text));
        foreach ($numbers as $number) {
            $normalized = preg_replace('/[^\d]/', '', $number);
            if ($normalized === '') {
                continue;
            }
            if ($digitsInText === '' || mb_strpos((string) $digitsInText, (string) $normalized) === false) {
                return false;
            }
        }
        return true;
    }

    private static function bestSnippet(string $content, array $mustTerms, array $mustPhrases, int $size = 650): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $content));
        if (mb_strlen($text) <= $size) {
            return $text;
        }

        $needles = array_merge($mustTerms, array_map('mb_strtolower', $mustPhrases));
        $pos = PHP_INT_MAX;

        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }
            $found = mb_stripos($text, $needle);
            if ($found !== false && $found < $pos) {
                $pos = (int) $found;
            }
        }

        if ($pos === PHP_INT_MAX) {
            $pos = 0;
        }

        $start   = max(0, $pos - (int) ($size / 3));
        $snippet = mb_substr($text, $start, $size);

        return ($start > 0 ? '…' : '') . $snippet . (mb_strlen($text) > $start + $size ? '…' : '');
    }

    private static function tableColumns(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        global $wpdb;

        $columns = [];
        $results = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        if (is_array($results)) {
            foreach ($results as $column) {
                $name = strtolower((string) ($column['Field'] ?? ''));
                if ($name !== '') {
                    $columns[$name] = true;
                }
            }
        }
        $cache[$table] = $columns;

        return $columns;
    }

    private static function fulltextColumnsByIndex(string $table): array
    {
        global $wpdb;
        $results = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A) ?: [];
        $byKey = [];
        foreach ($results as $row) {
            $type = strtolower((string) ($row['Index_type'] ?? ''));
            if ($type !== 'fulltext') {
                continue;
            }
            $key = (string) ($row['Key_name'] ?? '');
            $column = (string) ($row['Column_name'] ?? '');
            if ($key !== '' && $column !== '') {
                $byKey[$key][] = $column;
            }
        }
        return $byKey;
    }

    private static function isHubUrl(string $url, string $cpt): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $path = rtrim($path, '/');
        $needle = '/' . trim($cpt, '/');
        return $path === $needle || $path === $needle . '/index';
    }
}
