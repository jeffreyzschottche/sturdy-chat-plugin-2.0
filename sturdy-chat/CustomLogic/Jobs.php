<?php
if (!defined('ABSPATH'))
    exit;

class SturdyChat_CPT_Jobs implements SturdyChat_CPT_Module
{
    /**
     * Enhances the content of a post by appending specific job-related information if applicable.
     *
     * @param WP_Post $post The WordPress post object. Only posts of type 'jobs' will be processed.
     * @param array $parts The initial array of content parts to be enriched.
     * @param array $settings Additional settings that can be used to modify the enrichment behavior.
     *
     * @return array The updated array of content parts with appended job-related information, if available.
     */
    public function enrich_content(WP_Post $post, array $parts, array $settings): array
    {
        if ($post->post_type !== 'jobs')
            return $parts;

        $loc = get_post_meta($post->ID, 'locatie', true);
        $sal = get_post_meta($post->ID, 'salaris', true);
        if ($loc !== '')
            $parts[] = 'LOCATIE: ' . $loc;
        if ($sal !== '')
            $parts[] = 'SALARIS: ' . $sal;
        return $parts;
    }

    /**
     * Handles a given query to determine user intent and provide relevant job listings or links to vacancies.
     *
     * @param string $q A query string entered by the user, which is analyzed for intent and filters.
     * @param array $settings Additional settings that may influence query handling behavior.
     *
     * @return array|null An associative array containing a response with job-related information and sources,
     *                    or null if the input query does not indicate job-related intent.
     */
    public function handle_query(string $q, array $settings): ?array
    {
        $intent = $this->detectVacanciesIntent($q);
        if (!($intent['link'] || $intent['list']))
            return null;

        $url = $this->getVacanciesUrl();

        if ($intent['link'] && !$intent['list']) {
            return [
                'answer' => 'Je kunt alle openstaande vacatures hier bekijken: ' . $url,
                'sources' => [['title' => 'Vacatures', 'url' => $url, 'score' => 1.0]],
            ];
        }

        // Lijstmodus
        $minWanted = $this->extractMinSalary($q);
        $keywords = $this->extractJobKeywords($q);
        $jobs = get_posts([
            'post_type' => 'jobs',
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
            'suppress_filters' => true,
        ]);

        $locTokens = $this->extractLocationTokens($q);

        if (!empty($keywords) && $jobs) {
            $jobs = array_values(array_filter($jobs, fn($p) => $this->postMatchesKeywords($p, $keywords)));
        }
        if ($minWanted !== null && $jobs) {
            $jobs = array_values(array_filter($jobs, function ($p) use ($minWanted) {
                $sal = get_post_meta($p->ID, 'salaris', true);
                if ($sal === '' || $sal === null)
                    return false;
                $r = $this->parseSalaryRange($sal);
                return ($r['min'] !== null && $r['min'] >= $minWanted);
            }));
        }
        if (!empty($locTokens) && $jobs) {
            $jobs = array_values(array_filter($jobs, fn($p) => $this->postMatchesLocation($p, $locTokens)));
        }

        if ($jobs) {
            $jobs = array_slice($jobs, 0, 10);
            $lines = [];
            $sources = [];
            foreach ($jobs as $p) {
                $loc = get_post_meta($p->ID, 'locatie', true);
                $sal = get_post_meta($p->ID, 'salaris', true);
                $perma = get_permalink($p);
                $label = get_the_title($p);
                $meta = trim(($loc ? $loc : '') . ($sal ? ' — ' . $sal : ''));
                $lines[] = '• ' . $label . ($meta ? ' — ' . $meta : '') . ' — ' . $perma;
                $sources[] = ['title' => $label, 'url' => $perma, 'score' => 1.0];
            }

            $prefixParts = [];
            if (!empty($keywords))
                $prefixParts[] = implode(', ', $keywords);
            if ($minWanted !== null)
                $prefixParts[] = 'vanaf €' . number_format($minWanted, 0, ',', '.') . ' p/m';
            $prefix = !empty($prefixParts)
                ? 'Hier zijn de vacatures (' . implode(' • ', $prefixParts) . ', ' . count($jobs) . '):'
                : 'Hier zijn de huidige vacatures (' . count($jobs) . '):';

            return [
                'answer' => $prefix . "\n" . implode("\n", $lines) . "\n\nAlle vacatures: " . $url,
                'sources' => $sources,
            ];
        }

        $why = [];
        if (!empty($keywords))
            $why[] = 'matching je zoektermen';
        if ($minWanted !== null)
            $why[] = 'die voldoen aan je salariswens';
        $suffix = $why ? ' ' . implode(' en ', $why) : '';

        return [
            'answer' => 'Op dit moment staan er geen vacatures gepubliceerd' . $suffix . '. Check later opnieuw of kijk hier: ' . $url,
            'sources' => [['title' => 'Vacatures', 'url' => $url, 'score' => 1.0]],
        ];
    }

    /* ---------- Helpers (ongewijzigde logica, nu ingekapseld) ---------- */

    private function getVacanciesUrl(): string
    {
        $page = get_page_by_path('vacatures');
        if ($page && $page->post_status === 'publish')
            return get_permalink($page);
        return home_url('/jobs/');
    }

    private function detectVacanciesIntent(string $q): array
    {
        $t = mb_strtolower($q);
        $triggers = ['vacature', 'vacatures', 'job', 'jobs', 'baan', 'banen', 'functie', 'functies', 'positie', 'posities'];
        $hasVac = false;
        foreach ($triggers as $w)
            if (strpos($t, $w) !== false) {
                $hasVac = true;
                break;
            }
        if (!$hasVac)
            return ['link' => false, 'list' => false];
        $wantsLink = (strpos($t, 'link') !== false || strpos($t, 'url') !== false || strpos($t, 'pagina') !== false || strpos($t, 'waar') !== false);
        $wantsList = (strpos($t, 'welke') !== false || strpos($t, 'openstaand') !== false || strpos($t, 'hebt') !== false || strpos($t, 'hebben') !== false || strpos($t, 'lijst') !== false || strpos($t, 'overzicht') !== false);
        return ['link' => $wantsLink, 'list' => $wantsList || !$wantsLink];
    }

    private function parseSalaryRange(string $s): array
    {
        $s = str_replace(["\xE2\x80\x93", "\xE2\x80\x94", '–', '—', '~', ' to '], '-', (string) $s);
        $norm = preg_replace('/[^0-9\-]/u', '', $s);
        if (strpos($norm, '-') !== false) {
            [$a, $b] = array_pad(explode('-', $norm, 2), 2, '');
            $min = $a !== '' ? (int) $a : null;
            $max = $b !== '' ? (int) $b : null;
            return ['min' => $min, 'max' => $max];
        }
        $val = $norm !== '' ? (int) $norm : null;
        return ['min' => $val, 'max' => $val];
    }

    private function extractMinSalary(string $q): ?int
    {
        $t = mb_strtolower($q);
        $atLeastHints = ['minimaal', 'vanaf', 'meer dan', 'min', '>=', 'boven', 'hoger dan'];
        $hasAtLeast = false;
        foreach ($atLeastHints as $h)
            if (strpos($t, $h) !== false) {
                $hasAtLeast = true;
                break;
            }
        if (preg_match('/\d[\d\.\,]*/u', $t, $m)) {
            $digits = (int) preg_replace('/[^\d]/', '', $m[0]);
            if ($digits > 0) {
                if ($hasAtLeast)
                    return $digits;
                if (strpos($t, 'salar') !== false || strpos($t, 'per maand') !== false || strpos($t, 'p/m') !== false)
                    return $digits;
            }
        }
        return null;
    }

    private function extractJobKeywords(string $q): array
    {
        $t = mb_strtolower($q);
        $map = [
            'frontend' => ['front-end', 'frontend', 'front end'],
            'backend' => ['back-end', 'backend', 'back end'],
            'fullstack' => ['full-stack', 'full stack', 'fullstack'],
            'react' => ['react'],
            'php' => ['php'],
            'laravel' => ['laravel'],
            'devops' => ['devops'],
            'designer' => ['designer', 'ux', 'ui'],
            'cloud' => ['cloud'],
            'developer' => ['developer', 'ontwikkelaar', 'engineer'],
        ];
        $out = [];
        foreach ($map as $key => $alts) {
            foreach ($alts as $a)
                if (strpos($t, $a) !== false) {
                    $out[] = $key;
                    break;
                }
        }
        return array_values(array_unique($out));
    }

    private function extractLocationTokens(string $q): array
    {
        $t = mb_strtolower($q);
        $t = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $t);
        $t = preg_replace('/\s+/u', ' ', $t);
        $candidates = ['amsterdam', 'utrecht', 'rotterdam', 'eindhoven', 'den haag', "s-gravenhage", 'groningen', 'tilburg', 'nijmegen', 'haarlem', 'arnhem', 'enschede', 'apeldoorn', 'maastricht', 'leiden', 'delft', 'almere', 'amersfoort', 'breda', 'zwolle', 'remote'];
        $found = [];
        foreach ($candidates as $city) {
            $alt = str_replace("'", "", $city);
            if (strpos($t, $city) !== false || ($alt !== $city && strpos($t, $alt) !== false))
                $found[] = $city;
        }
        return array_values(array_unique($found));
    }

    private function postMatchesKeywords($p, array $keywords): bool
    {
        if (empty($keywords))
            return true;
        $hay = mb_strtolower(get_the_title($p) . ' ' . wp_strip_all_tags($p->post_content));
        $hayCompact = str_replace(['-', ' '], '', $hay);
        $groups = [
            'frontend' => ['frontend', 'front-end', 'front end'],
            'backend' => ['backend', 'back-end', 'back end'],
            'fullstack' => ['fullstack', 'full-stack', 'full stack'],
            'react' => ['react'],
            'php' => ['php'],
            'laravel' => ['laravel'],
            'devops' => ['devops'],
            'designer' => ['designer', 'ux', 'ui'],
            'cloud' => ['cloud'],
            'developer' => ['developer', 'ontwikkelaar', 'engineer'],
            'engineer' => ['engineer'],
        ];
        foreach ($keywords as $k) {
            $alts = $groups[$k] ?? [$k];
            $ok = false;
            foreach ($alts as $alt) {
                $altL = mb_strtolower($alt);
                $altC = str_replace(['-', ' '], '', $altL);
                if (strpos($hay, $altL) !== false || strpos($hayCompact, $altC) !== false) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok)
                return false;
        }
        return true;
    }

    private function postMatchesLocation($p, array $locTokens): bool
    {
        if (empty($locTokens))
            return true;
        $loc = mb_strtolower((string) get_post_meta($p->ID, 'locatie', true));
        if ($loc === '')
            return false;
        foreach ($locTokens as $tok)
            if (strpos($loc, $tok) !== false)
                return true;
        return false;
    }
}
