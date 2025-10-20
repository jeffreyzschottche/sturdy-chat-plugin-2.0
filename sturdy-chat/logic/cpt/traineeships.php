<?php
if (!defined('ABSPATH'))
    exit;

class SturdyChat_CPT_Traineeships implements SturdyChat_CPT_Module
{
    /**
     * Enriches the content of a given post by adding additional information
     * based on metadata fields specific to the 'traineeships' post type.
     *
     * @param WP_Post $post The WordPress post object being processed.
     * @param array $parts An array of content parts to be enriched.
     * @param array $settings An array of settings that may influence the enrichment logic.
     * @return array The enriched array of content parts.
     */
    public function enrich_content(WP_Post $post, array $parts, array $settings): array
    {
        if ($post->post_type !== 'traineeships')
            return $parts;

        $loc = get_post_meta($post->ID, 'locatie', true);
        $min = get_post_meta($post->ID, 'salaris_min', true);
        $max = get_post_meta($post->ID, 'salaris_max', true);

        if ($loc !== '')
            $parts[] = 'LOCATIE: ' . $loc;
        if ($min !== '' || $max !== '') {
            $parts[] = 'SALARIS: min=' . (int) $min . ' max=' . (int) $max;
        }
        return $parts;
    }

    /**
     * Handles a query to search for traineeships based on specific triggers and filters.
     *
     * @param string $q The input query to be processed.
     * @param array $settings Additional settings or parameters for query handling.
     * @return array|null Returns an array with the answer and sources if matching traineeships are found,
     *                    or null if no relevant query triggers are detected.
     */
    public function handle_query(string $q, array $settings): ?array
    {
        $t = mb_strtolower($q);
        $triggers = ['traineeship', 'traineeships', 'trainee', 'starter', 'startersfunctie'];
        $match = false;
        foreach ($triggers as $w) {
            if (strpos($t, $w) !== false) {
                $match = true;
                break;
            }
        }
        if (!$match)
            return null;

        $url = $this->getArchiveUrl();

        // Sometimes link only would be better?
        if (strpos($t, 'link') !== false || strpos($t, 'waar') !== false || strpos($t, 'pagina') !== false || strpos($t, 'overzicht') !== false) {
            return [
                'answer' => 'Je vindt alle traineeships hier: ' . $url,
                'sources' => [['title' => 'Traineeships', 'url' => $url, 'score' => 1.0]],
            ];
        }

        // Filters
        $minWanted = $this->extractMinSalary($t);
        $locTokens = $this->extractLocationTokens($t);

        $items = get_posts([
            'post_type' => 'traineeships',
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
            'suppress_filters' => true,
        ]);

        if ($minWanted !== null && $items) {
            $items = array_values(array_filter($items, function ($p) use ($minWanted) {
                $min = (int) get_post_meta($p->ID, 'salaris_min', true);
                return $min > 0 && $min >= $minWanted;
            }));
        }

        if (!empty($locTokens) && $items) {
            $items = array_values(array_filter($items, function ($p) use ($locTokens) {
                $loc = mb_strtolower((string) get_post_meta($p->ID, 'locatie', true));
                foreach ($locTokens as $tok) {
                    if ($loc !== '' && strpos($loc, $tok) !== false)
                        return true;
                }
                return false;
            }));
        }

        if ($items) {
            $items = array_slice($items, 0, 10);
            $lines = [];
            $sources = [];
            foreach ($items as $p) {
                $loc = get_post_meta($p->ID, 'locatie', true);
                $min = (int) get_post_meta($p->ID, 'salaris_min', true);
                $max = (int) get_post_meta($p->ID, 'salaris_max', true);
                $perma = get_permalink($p);
                $label = get_the_title($p);

                $metaBits = [];
                if ($loc)
                    $metaBits[] = $loc;
                if ($min || $max) {
                    $salary = '€' . ($min ? number_format($min, 0, ',', '.') : '-') . '–€' . ($max ? number_format($max, 0, ',', '.') : '-');
                    $metaBits[] = $salary . ' p/m';
                }
                $meta = $metaBits ? ' — ' . implode(' — ', $metaBits) : '';

                $lines[] = '• ' . $label . $meta . ' — ' . $perma;
                $sources[] = ['title' => $label, 'url' => $perma, 'score' => 1.0];
            }

            $prefixParts = [];
            if ($minWanted !== null)
                $prefixParts[] = 'vanaf €' . number_format($minWanted, 0, ',', '.') . ' p/m';
            if (!empty($locTokens))
                $prefixParts[] = implode(', ', $locTokens);
            $prefix = $prefixParts ? 'Hier zijn de traineeships (' . implode(' • ', $prefixParts) . ', ' . count($items) . '):'
                : 'Hier zijn de huidige traineeships (' . count($items) . '):';

            return [
                'answer' => $prefix . "\n" . implode("\n", $lines) . "\n\nAlle traineeships: " . $url,
                'sources' => $sources,
            ];
        }

        return [
            'answer' => 'Op dit moment zijn er geen geschikte traineeships gevonden. Probeer later opnieuw of kijk hier: ' . $url,
            'sources' => [['title' => 'Traineeships', 'url' => $url, 'score' => 1.0]],
        ];
    }

    /** Helpers */

    private function getArchiveUrl(): string
    {
        $page = get_page_by_path('traineeships');
        if ($page && $page->post_status === 'publish')
            return get_permalink($page);
        return home_url('/traineeships/');
    }

    private function extractMinSalary(string $t): ?int
    {
        $hints = ['minimaal', 'vanaf', 'meer dan', 'min', '>=', 'boven', 'hoger dan'];
        $atLeast = false;
        foreach ($hints as $h) {
            if (strpos($t, $h) !== false) {
                $atLeast = true;
                break;
            }
        }
        if (preg_match('/\d[\d\.\,]*/u', $t, $m)) {
            $digits = (int) preg_replace('/[^\d]/', '', $m[0]);
            if ($digits > 0)
                return $digits;
        }
        return $atLeast ? 0 : null;
    }

    private function extractLocationTokens(string $t): array
    {
        $t = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $t);
        $t = preg_replace('/\s+/u', ' ', $t);
        $t = mb_strtolower(trim($t));

        $candidates = ['amsterdam', 'utrecht', 'rotterdam', 'eindhoven', 'den haag', "s-gravenhage", 'groningen', 'tilburg', 'nijmegen', 'haarlem', 'arnhem', 'enschede', 'apeldoorn', 'maastricht', 'leiden', 'delft', 'almere', 'amersfoort', 'breda', 'zwolle', 'remote'];
        $found = [];
        foreach ($candidates as $city) {
            $alt = str_replace("'", "", $city);
            if (strpos($t, $city) !== false || ($alt !== $city && strpos($t, $alt) !== false))
                $found[] = $city;
        }
        return array_values(array_unique($found));
    }
}
