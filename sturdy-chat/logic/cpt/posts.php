<?php
if (!defined('ABSPATH')) exit;

class SturdyChat_CPT_Posts implements SturdyChat_CPT_Module
{
    public function enrich_content(WP_Post $post, array $parts, array $settings): array
    {
        return $parts;
    }

    public function handle_query(string $q, array $settings): ?array
    {
        $t = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $q)));

        if (!$this->mentionsNews($t)) {
            return null;
        }

        // 1) Parse intent + (optionele) doel-datum
        $intent = $this->parseIntent($t);
        if ($intent['kind'] === null) {
            return null; // laat RAG het proberen
        }

        // 2) Stel post types vast
        $defaultTypes = ['post'];
        $newsTypes    = ['news', 'nieuws', 'article', 'knowledge_article'];
        $postTypes    = $intent['newsOnly']
            ? $this->unique(array_merge($defaultTypes, (array)($settings['news_post_types'] ?? $newsTypes)))
            : $this->unique((array)($settings['posts_post_types'] ?? $defaultTypes));
        if (!$postTypes) $postTypes = $defaultTypes;

        // 3) Optioneel: filter op categorie 'nieuws'
        $taxQuery = [];
        if ($intent['newsOnly']) {
            $newsCatSlug = $settings['news_category_slug'] ?? 'nieuws';
            $taxQuery[] = [
                'taxonomy' => 'category',
                'field'    => 'slug',
                'terms'    => [$newsCatSlug],
            ];
        }

        // 4) Intent afhandelen
        if ($intent['kind'] === 'by_date') {
            // Zoek nieuws/bericht op specifieke datum
            $args = [
                'post_type'        => $postTypes,
                'post_status'      => 'publish',
                'posts_per_page'   => 3,
                'orderby'          => 'date',
                'order'            => 'DESC',
                'suppress_filters' => true,
                'date_query'       => [[
                    'year'  => (int)$intent['date']['y'],
                    'month' => (int)$intent['date']['m'],
                    'day'   => (int)$intent['date']['d'],
                ]],
            ];
            if ($taxQuery) {
                $args['tax_query'] = [
                    'relation' => 'OR',
                    $taxQuery[0],
                ];
            }

            $items = get_posts($args);
            if (empty($items)) {
                return [
                    'answer'  => 'Ik kon geen artikel op die datum vinden.',
                    'sources' => [],
                ];
            }
            return $this->respondList($items, 'Gevonden artikelen op die datum');
        }

        if ($intent['kind'] === 'newest' || $intent['kind'] === 'oldest' || $intent['kind'] === 'newest_fallback') {
            $order = ($intent['kind'] === 'oldest') ? 'ASC' : 'DESC';

            $args = [
                'post_type'        => $postTypes,
                'post_status'      => 'publish',
                'posts_per_page'   => 1,
                'orderby'          => 'date',
                'order'            => $order,
                'suppress_filters' => true,
            ];
            if ($taxQuery) {
                $args['tax_query'] = [
                    'relation' => 'OR',
                    $taxQuery[0],
                ];
            }

            $items = get_posts($args);

            // Fallback: bij newsOnly nog even zonder tax_query proberen
            if ($intent['newsOnly'] && empty($items)) {
                unset($args['tax_query']);
                $items = get_posts($args);
            }

            if (empty($items)) {
                return [
                    'answer'  => ($intent['kind'] === 'oldest')
                        ? 'Ik kan nu geen ouder gepubliceerd artikel vinden.'
                        : 'Ik kan nu geen recent gepubliceerd artikel vinden.',
                    'sources' => [],
                ];
            }

            $p     = $items[0];
            $url   = get_permalink($p);
            $title = $this->cleanTitle(get_the_title($p));
            $date  = get_the_date('j F Y', $p);

            $prefix = $intent['newsOnly']
                ? (($intent['kind'] === 'oldest') ? 'Het oudste nieuwsartikel' : 'Het nieuwste nieuwsartikel')
                : (($intent['kind'] === 'oldest') ? 'Het oudste artikel' : 'Het nieuwste artikel');

            $answer = sprintf('%s is “%s” (%s).', $prefix, $title, $date);

            return [
                'answer'  => $answer,
                'sources' => [[
                    'title' => $title,
                    'url'   => $url,
                    'score' => 1.0,
                ]],
            ];
        }

        if ($intent['kind'] === 'search_news') {
            $terms = $intent['terms'];
            if ($terms === '') {
                return null;
            }
            $args = [
                'post_type'        => $postTypes,
                'post_status'      => 'publish',
                'posts_per_page'   => 3,
                'orderby'          => 'date',
                'order'            => 'DESC',
                's'                => $terms,
                'suppress_filters' => true,
            ];
            if ($taxQuery) {
                $args['tax_query'] = [
                    'relation' => 'OR',
                    $taxQuery[0],
                ];
            }

            $items = get_posts($args);
            if (empty($items)) {
                return [
                    'answer'  => "Ik heb geen nieuwsartikel gevonden over “{$terms}”.",
                    'sources' => [],
                ];
            }
            return $this->respondList($items, "Nieuwsartikelen over “{$terms}”");
        }

        return null;
    }

    /* -------------------- Intent helpers -------------------- */

    private function parseIntent(string $t): array
    {
        $newsOnly = $this->mentionsNews($t);

        // 1) datumherkenning (“Gepubliceerd: 16 september 2025”, “op 16-09-2025”, etc.)
        $date = $this->extractNlDate($t);
        if ($date) {
            return ['kind' => 'by_date', 'newsOnly' => $newsOnly, 'terms' => '', 'date' => $date];
        }

        // 2) nieuwste / laatste / recentste
        if ($this->hasAny($t, ['nieuwste','laatste','recentste','meest recente','net verschenen','zojuist','vandaag'])) {
            if ($this->hasAny($t, ['artikel','bericht','post','posts','artikelen','berichten','nieuws','nieuwsartikel'])) {
                return ['kind' => 'newest', 'newsOnly' => $newsOnly, 'terms' => ''];
            }
        }

        // 3) oudste
        if (mb_strpos($t, 'oudste') !== false && $this->hasAny($t, ['artikel','bericht','post','posts','artikelen','berichten','nieuws','nieuwsartikel'])) {
            return ['kind' => 'oldest', 'newsOnly' => $newsOnly, 'terms' => ''];
        }

        // 4) zoek in nieuws “over …”
        if ($newsOnly) {
            if (preg_match('/(?:nieuws(?:\s*artikel)?|nieuwsartikel|artikel.*?nieuws).*?(?:over|met betrekking tot)\s+(.+)$/u', $t, $m)) {
                $terms = trim($m[1], " .?!,;:—–-");
                return ['kind' => 'search_news', 'newsOnly' => true, 'terms' => $terms];
            }
            if (preg_match('/nieuws(?:\s*artikel)?\s+over\s+(.+)$/u', $t, $m2)) {
                $terms = trim($m2[1], " .?!,;:—–-");
                return ['kind' => 'search_news', 'newsOnly' => true, 'terms' => $terms];
            }
        }

        // 5) “wat is het nieuws artikel” → behandel als “newest” (gebruikersvraag is vaag, maar intent = laatste nieuws)
        if ($newsOnly && $this->hasAny($t, ['wat is het nieuws artikel','wat is het nieuwsartikel','het nieuws artikel','het nieuwsartikel'])) {
            return ['kind' => 'newest_fallback', 'newsOnly' => true, 'terms' => ''];
        }

        return ['kind' => null, 'newsOnly' => false, 'terms' => ''];
    }

    private function mentionsNews(string $t): bool
    {
        return $this->hasAny($t, ['nieuws','nieuwsartikel','nieuws artikelen','news']);
    }

    private function hasAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($n !== '' && mb_strpos($haystack, $n) !== false) return true;
        }
        return false;
    }

    private function extractNlDate(string $t): ?array
    {
        // 16 september 2025
        $months = [
            'januari'=>1,'februari'=>2,'maart'=>3,'april'=>4,'mei'=>5,'juni'=>6,
            'juli'=>7,'augustus'=>8,'september'=>9,'oktober'=>10,'november'=>11,'december'=>12
        ];
        if (preg_match('/(\d{1,2})\s+(januari|februari|maart|april|mei|juni|juli|augustus|september|oktober|november|december)\s+(\d{4})/u', $t, $m)) {
            $d = (int)$m[1]; $mth = $months[$m[2]] ?? 0; $y = (int)$m[3];
            if ($d>=1 && $d<=31 && $mth>=1) return ['y'=>$y,'m'=>$mth,'d'=>$d];
        }
        // 16-09-2025 of 16/09/2025
        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})\b/u', $t, $m2)) {
            $d=(int)$m2[1]; $mth=(int)$m2[2]; $y=(int)$m2[3];
            if ($d>=1 && $d<=31 && $mth>=1 && $mth<=12) return ['y'=>$y,'m'=>$mth,'d'=>$d];
        }
        return null;
    }

    /* -------------------- Output helpers -------------------- */

    private function respondList(array $items, string $heading): array
    {
        $lines = [];
        $sources = [];
        foreach ($items as $p) {
            $title = $this->cleanTitle(get_the_title($p));
            $url   = get_permalink($p);
            $date  = get_the_date('j F Y', $p);
            $lines[] = "• {$title} ({$date}) — {$url}";
            $sources[] = ['title' => $title, 'url' => $url, 'score' => 1.0];
        }
        return [
            'answer'  => $heading . ":\n" . implode("\n", $lines),
            'sources' => $sources,
        ];
    }

    private function unique(array $arr): array
    {
        $out = [];
        foreach ($arr as $v) {
            $v = (string)$v;
            if ($v === '') continue;
            if (!in_array($v, $out, true)) $out[] = $v;
        }
        return $out;
    }

    private function cleanTitle(string $raw): string
    {
        return wp_strip_all_tags(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
