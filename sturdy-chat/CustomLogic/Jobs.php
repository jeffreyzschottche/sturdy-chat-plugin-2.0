<?php
if (!defined('ABSPATH')) {
    exit;
}

class SturdyChat_CPT_Jobs implements SturdyChat_CPT_Module
{
    private const SUPPORTED_POST_TYPES = ['jobs', 'job', 'vacancy', 'vacancies'];
    private const INTENT_TERMS = [
        'job', 'jobs', 'career', 'careers', 'carriere', 'carrière',
        'werkenbij', 'werken bij', 'werken-bij', 'werken', 'werk', 'werkzaamheden',
        'salaris', 'salary', 'loon', 'beloning',
        'locatie', 'adres', 'city', 'stad', 'thuiswerken', 'remote',
        'dienstverband', 'contract', 'contracttype',
        'vacature', 'vacatures', 'vacancy', 'vacancies', 'openstaandevacatures', 'openstaande vacatures',
        'baan', 'banen', 'baantje', 'arbeid', 'arbeidsvoorwaarden',
        'zzp', 'freelance', 'interim',
        'internship', 'traineeship', 'trainee', 'stage',
        'part-time', 'parttime', 'part time',
        'full-time', 'fulltime', 'full time',
    ];
    private const LINK_HINTS = ['link', 'url', 'waar', 'pagina', 'solliciteer', 'solliciteren'];
    private const LIST_HINTS = ['welke', 'openstaand', 'lijst', 'overzicht', 'toon', 'beschikbaar', 'zoek', 'hebben', 'heb je', 'laat zien'];
    private const EMPLOYMENT_LABELS = [
        'FULL_TIME' => 'Fulltime',
        'PART_TIME' => 'Parttime',
        'CONTRACTOR' => 'Contractor',
        'TEMPORARY' => 'Tijdelijk',
        'INTERN' => 'Stage',
        'VOLUNTEER' => 'Vrijwilliger',
        'PER_DIEM' => 'Op afroep',
        'OTHER' => 'Overig',
    ];

    /**
     * Voeg relevante vacaturemeta toe aan de tekst die we indexeren.
     */
    public function enrich_content(WP_Post $post, array $parts, array $settings): array
    {
        if (!$this->isJobPost($post)) {
            return $parts;
        }

        $meta = $this->collectJobMeta($post);
        foreach ($meta as $label => $value) {
            if ($value === '') {
                continue;
            }
            $parts[] = strtoupper($label) . ': ' . $value;
        }

        return $parts;
    }

    /**
     * Herkent vacature-intent en geeft zo mogelijk direct een antwoord (zonder RAG).
     */
    public function handle_query(string $q, array $settings): ?array
    {
        $intent = $this->detectVacanciesIntent($q);
        if (!($intent['link'] || $intent['list'])) {
            return null;
        }

        $url = $this->getVacanciesUrl();

        if ($intent['link'] && !$intent['list']) {
            return [
                'answer'  => 'Je kunt alle openstaande vacatures hier bekijken: ' . $url,
                'sources' => [['title' => 'Vacatures', 'url' => $url, 'score' => 1.0]],
            ];
        }

        $minWanted = $this->extractMinSalary($q);
        $keywords  = $this->extractJobKeywords($q);
        $locTokens = $this->extractLocationTokens($q);

        $jobs = get_posts([
            'post_type'        => $this->jobPostTypes(),
            'post_status'      => 'publish',
            'posts_per_page'   => 50,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'suppress_filters' => true,
        ]);

        if (!empty($keywords) && $jobs) {
            $jobs = array_values(array_filter($jobs, fn($p) => $this->postMatchesKeywords($p, $keywords)));
        }
        if ($minWanted !== null && $jobs) {
            $jobs = array_values(array_filter($jobs, fn($p) => $this->postMeetsMinSalary($p, $minWanted)));
        }
        if (!empty($locTokens) && $jobs) {
            $jobs = array_values(array_filter($jobs, fn($p) => $this->postMatchesLocation($p, $locTokens)));
        }

        if ($jobs) {
            $jobs    = array_slice($jobs, 0, 10);
            $lines   = [];
            $sources = [];
            foreach ($jobs as $p) {
                $meta    = $this->collectJobMeta($p);
                $perma   = get_permalink($p);
                $label   = get_the_title($p);
                $summary = $this->summarizeMetaForAnswer($meta);

                $line = '• ' . $label;
                if ($summary) {
                    $line .= ' — ' . implode(' • ', $summary);
                }
                $line .= ' — ' . $perma;

                if (!empty($meta['Solliciteer'])) {
                    $line .= ' — Solliciteer: ' . $meta['Solliciteer'];
                }

                $lines[]   = $line;
                $sources[] = ['title' => $label, 'url' => $perma, 'score' => 1.0];
            }

            $prefixParts = [];
            if (!empty($keywords)) {
                $prefixParts[] = implode(', ', $keywords);
            }
            if ($minWanted !== null) {
                $prefixParts[] = 'vanaf €' . number_format($minWanted, 0, ',', '.') . ' p/m';
            }
            $prefix = $prefixParts
                ? 'Hier zijn de vacatures (' . implode(' • ', $prefixParts) . ', ' . count($jobs) . '):'
                : 'Hier zijn de huidige vacatures (' . count($jobs) . '):';

            return [
                'answer'  => $prefix . "\n" . implode("\n", $lines) . "\n\nAlle vacatures: " . $url,
                'sources' => $sources,
            ];
        }

        $why = [];
        if (!empty($keywords)) {
            $why[] = 'matching je zoektermen';
        }
        if ($minWanted !== null) {
            $why[] = 'die voldoen aan je salariswens';
        }
        if (!empty($locTokens)) {
            $why[] = 'in de gevraagde locatie';
        }
        $suffix = $why ? ' ' . implode(' en ', $why) : '';

        return [
            'answer'  => 'Op dit moment staan er geen vacatures gepubliceerd' . $suffix . '. Check later opnieuw of kijk hier: ' . $url,
            'sources' => [['title' => 'Vacatures', 'url' => $url, 'score' => 1.0]],
        ];
    }

    /* ---------------------- Helpers ---------------------- */

    private function getVacanciesUrl(): string
    {
        $slugs = ['vacatures', 'werken-bij', 'werkenbij'];
        foreach ($slugs as $slug) {
            $page = get_page_by_path($slug);
            if ($page && $page->post_status === 'publish') {
                return get_permalink($page);
            }
        }
        return home_url('/jobs/');
    }

    private function detectVacanciesIntent(string $q): array
    {
        $t = mb_strtolower($q);
        if (!$this->containsAny($t, self::INTENT_TERMS)) {
            return ['link' => false, 'list' => false];
        }
        $wantsLink = $this->containsAny($t, self::LINK_HINTS);
        $wantsList = $this->containsAny($t, self::LIST_HINTS) || !$wantsLink;
        return ['link' => $wantsLink, 'list' => $wantsList];
    }

    private function parseSalaryRange(string $s): array
    {
        $s    = str_replace(["\xE2\x80\x93", "\xE2\x80\x94", '–', '—', '~', ' to '], '-', (string) $s);
        $norm = preg_replace('/[^0-9\-]/u', '', $s);
        if (strpos($norm, '-') !== false) {
            [$a, $b] = array_pad(explode('-', $norm, 2), 2, '');
            $min     = $a !== '' ? (int) $a : null;
            $max     = $b !== '' ? (int) $b : null;
            return ['min' => $min, 'max' => $max];
        }
        $val = $norm !== '' ? (int) $norm : null;
        return ['min' => $val, 'max' => $val];
    }

    private function extractMinSalary(string $q): ?int
    {
        $t            = mb_strtolower($q);
        $atLeastHints = ['minimaal', 'vanaf', 'meer dan', 'min', '>=', 'boven', 'hoger dan'];
        $hasAtLeast   = $this->containsAny($t, $atLeastHints);

        if (preg_match('/\d[\d\.\,]*/u', $t, $m)) {
            $digits = (int) preg_replace('/[^\d]/', '', $m[0]);
            if ($digits > 0) {
                if ($hasAtLeast) {
                    return $digits;
                }
                if ($this->containsAny($t, ['salar', 'salary', 'loon', 'per maand', 'p/m', 'per uur'])) {
                    return $digits;
                }
            }
        }
        return null;
    }

    private function extractJobKeywords(string $q): array
    {
        $t   = mb_strtolower($q);
        $map = [
            'frontend'  => ['front-end', 'frontend', 'front end'],
            'backend'   => ['back-end', 'backend', 'back end'],
            'fullstack' => ['full-stack', 'full stack', 'fullstack'],
            'react'     => ['react'],
            'php'       => ['php'],
            'laravel'   => ['laravel'],
            'devops'    => ['devops'],
            'designer'  => ['designer', 'ux', 'ui'],
            'cloud'     => ['cloud'],
            'developer' => ['developer', 'ontwikkelaar', 'engineer'],
            'sales'     => ['sales', 'accountmanager'],
            'marketing' => ['marketing'],
            'data'      => ['data', 'analytics'],
        ];
        $out = [];
        foreach ($map as $key => $alts) {
            foreach ($alts as $a) {
                if (strpos($t, $a) !== false) {
                    $out[] = $key;
                    break;
                }
            }
        }
        return array_values(array_unique($out));
    }

    private function extractLocationTokens(string $q): array
    {
        $t = mb_strtolower($q);
        $t = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $t);
        $t = preg_replace('/\s+/u', ' ', $t);

        $candidates = [
            'amsterdam', 'utrecht', 'rotterdam', 'eindhoven', 'den haag', "s-gravenhage",
            'groningen', 'tilburg', 'nijmegen', 'haarlem', 'arnhem', 'enschede', 'apeldoorn',
            'maastricht', 'leiden', 'delft', 'almere', 'amersfoort', 'breda', 'zwolle',
            'remote', 'thuiswerken',
        ];

        $found = [];
        foreach ($candidates as $city) {
            $alt = str_replace("'", "", $city);
            if (strpos($t, $city) !== false || ($alt !== $city && strpos($t, $alt) !== false)) {
                $found[] = $city === 'thuiswerken' ? 'remote' : $city;
            }
        }
        return array_values(array_unique($found));
    }

    private function postMatchesKeywords($p, array $keywords): bool
    {
        if (empty($keywords)) {
            return true;
        }
        $hay        = mb_strtolower(get_the_title($p) . ' ' . wp_strip_all_tags($p->post_content));
        $hayCompact = str_replace(['-', ' '], '', $hay);
        $groups     = [
            'frontend'  => ['frontend', 'front-end', 'front end'],
            'backend'   => ['backend', 'back-end', 'back end'],
            'fullstack' => ['fullstack', 'full-stack', 'full stack'],
            'react'     => ['react'],
            'php'       => ['php'],
            'laravel'   => ['laravel'],
            'devops'    => ['devops'],
            'designer'  => ['designer', 'ux', 'ui'],
            'cloud'     => ['cloud'],
            'developer' => ['developer', 'ontwikkelaar', 'engineer'],
            'engineer'  => ['engineer'],
            'sales'     => ['sales', 'accountmanager'],
            'marketing' => ['marketing'],
            'data'      => ['data', 'analytics'],
        ];
        foreach ($keywords as $k) {
            $alts = $groups[$k] ?? [$k];
            $ok   = false;
            foreach ($alts as $alt) {
                $altL = mb_strtolower($alt);
                $altC = str_replace(['-', ' '], '', $altL);
                if (strpos($hay, $altL) !== false || strpos($hayCompact, $altC) !== false) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    private function postMatchesLocation($p, array $locTokens): bool
    {
        if (empty($locTokens)) {
            return true;
        }
        $hay = mb_strtolower($this->formatLocation($p->ID));
        if ($hay === '') {
            $hay = mb_strtolower((string) get_post_meta($p->ID, 'locatie', true));
        }
        if ($hay === '') {
            return false;
        }
        foreach ($locTokens as $tok) {
            if ($tok === 'remote') {
                if (strpos($hay, 'remote') !== false || strpos($hay, 'thuis') !== false) {
                    return true;
                }
            }
            if (strpos($hay, $tok) !== false) {
                return true;
            }
        }
        return false;
    }

    private function isJobPost(WP_Post $post): bool
    {
        return in_array($post->post_type, $this->jobPostTypes(), true);
    }

    private function jobPostTypes(): array
    {
        $available = array_filter(self::SUPPORTED_POST_TYPES, static fn($slug) => post_type_exists($slug));
        return $available ? array_values($available) : ['jobs'];
    }

    private function collectJobMeta(WP_Post $post): array
    {
        $meta = [];

        $location = $this->formatLocation($post->ID);
        if ($location !== '') {
            $meta['Locatie'] = $location;
        }

        $salary = $this->formatSalary($post->ID);
        if ($salary !== '') {
            $meta['Salaris'] = $salary;
        }

        $hours = trim((string) get_post_meta($post->ID, 'hours', true));
        if ($hours !== '') {
            $meta['Uren'] = $hours;
        }

        $employment = $this->formatEmployment(get_post_meta($post->ID, 'employment_type', true));
        if ($employment !== '') {
            $meta['Dienstverband'] = $employment;
        }

        $education = trim((string) get_post_meta($post->ID, 'education', true));
        if ($education !== '') {
            $meta['Opleiding'] = $education;
        }

        $valid = trim((string) get_post_meta($post->ID, 'valid_through', true));
        if ($valid !== '') {
            $meta['Geldig tot'] = $valid;
        }

        $application = $this->getApplicationLink($post->ID);
        if ($application) {
            $meta['Solliciteer'] = $application['url'];
        }

        return $meta;
    }

    private function formatLocation(int $postId): string
    {
        $legacy = trim((string) get_post_meta($postId, 'locatie', true));
        $addressParts = array_filter([
            trim((string) get_post_meta($postId, 'address', true)),
            trim((string) get_post_meta($postId, 'postcode', true)),
            trim((string) get_post_meta($postId, 'city', true)),
        ]);
        $regionParts = array_filter([
            trim((string) get_post_meta($postId, 'province', true)),
            trim((string) get_post_meta($postId, 'country', true)),
        ]);

        $parts = [];
        if ($legacy !== '') {
            $parts[] = $legacy;
        }
        if ($addressParts) {
            $parts[] = implode(', ', $addressParts);
        }
        if ($regionParts) {
            $parts[] = implode(', ', $regionParts);
        }

        return implode(' • ', array_filter($parts));
    }

    private function formatSalary(int $postId): string
    {
        $min      = $this->metaInt($postId, 'salary_min');
        $max      = $this->metaInt($postId, 'salary_max');
        $currency = trim((string) get_post_meta($postId, 'currency', true)) ?: 'EUR';
        $per      = trim((string) get_post_meta($postId, 'salary_per', true)) ?: 'MONTH';

        if ($min !== null || $max !== null) {
            $format = static fn($value) => number_format((float) $value, 0, ',', '.');
            $range  = $min !== null && $max !== null
                ? $format($min) . '–' . $format($max)
                : $format($min ?? $max);
            return sprintf('%s%s per %s', $currency === 'EUR' ? '€' : $currency . ' ', $range, $this->salaryPerLabel($per));
        }

        $legacy = trim((string) get_post_meta($postId, 'salaris', true));
        return $legacy;
    }

    private function salaryPerLabel(string $per): string
    {
        switch ($per) {
            case 'HOUR':
                return 'uur';
            case 'DAY':
                return 'dag';
            case 'WEEK':
                return 'week';
            case 'YEAR':
                return 'jaar';
            default:
                return 'maand';
        }
    }

    private function formatEmployment($raw): string
    {
        $raw = is_string($raw) ? strtoupper(trim($raw)) : '';
        if ($raw === '') {
            return '';
        }
        if (isset(self::EMPLOYMENT_LABELS[$raw])) {
            return self::EMPLOYMENT_LABELS[$raw];
        }
        return ucfirst(strtolower($raw));
    }

    private function getApplicationLink(int $postId): ?array
    {
        $raw = get_post_meta($postId, 'application_url', true);
        if (is_array($raw) && !empty($raw['url'])) {
            return [
                'url'   => esc_url_raw($raw['url']),
                'title' => isset($raw['title']) && $raw['title'] !== '' ? $raw['title'] : __('Solliciteer', 'sturdychat-chatbot'),
            ];
        }
        return null;
    }

    private function summarizeMetaForAnswer(array $meta): array
    {
        $parts = [];
        if (!empty($meta['Locatie'])) {
            $parts[] = $meta['Locatie'];
        }
        if (!empty($meta['Salaris'])) {
            $parts[] = $meta['Salaris'];
        }
        if (!empty($meta['Dienstverband'])) {
            $parts[] = 'Dienstverband: ' . $meta['Dienstverband'];
        }
        if (!empty($meta['Uren'])) {
            $parts[] = 'Uren: ' . $meta['Uren'];
        }
        return $parts;
    }

    private function postMeetsMinSalary($p, int $minWanted): bool
    {
        $min = $this->metaInt($p->ID, 'salary_min');
        if ($min !== null && $min >= $minWanted) {
            return true;
        }
        $legacy = get_post_meta($p->ID, 'salaris', true);
        if ($legacy === '' || $legacy === null) {
            return false;
        }
        $range = $this->parseSalaryRange($legacy);
        return $range['min'] !== null && $range['min'] >= $minWanted;
    }

    private function metaInt(int $postId, string $key): ?int
    {
        $raw = get_post_meta($postId, $key, true);
        if ($raw === '' || $raw === null) {
            return null;
        }
        if (!is_numeric($raw)) {
            return null;
        }
        $val = (int) $raw;
        return $val > 0 ? $val : null;
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }
            if (strpos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
