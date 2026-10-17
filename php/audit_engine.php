<?php
// ============================================================
// php/audit_engine.php — Core accessibility audit engine
// Crawls a URL, runs WCAG checks, stores results in MySQL
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/url_guard.php';

class AuditEngine {

    private PDO $db;
    private array $issues = [];
    private \DOMDocument $dom;
    private \DOMXPath $xpath;

    public function __construct() {
        $this->db = getDB();
    }

    // ── Entry point ────────────────────────────────────────
    public function run(int $projectId, int $userId, string $url): array {

        // 1. Create audit run record
        $runId = $this->createRun($projectId, $userId, $url);

        try {
            // 2. Fetch HTML
            $html = $this->fetchHtml($url);
            if (!$html) throw new \RuntimeException("Could not fetch URL: $url");

            // 3. Parse DOM
            libxml_use_internal_errors(true);
            $this->dom   = new \DOMDocument();
            $this->dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
            $this->xpath = new \DOMXPath($this->dom);

            // 4. Run all checks
            $this->check_1_1_1_nonTextContent();
            $this->check_1_3_1_infoRelationships();
            $this->check_1_4_3_contrast();
            $this->check_1_4_10_reflow();
            $this->check_2_1_1_keyboard();
            $this->check_2_4_1_skipNav();
            $this->check_2_4_2_pageTitle();
            $this->check_2_4_4_linkPurpose();
            $this->check_2_4_6_headings();
            $this->check_2_4_7_focusVisible();
            $this->check_3_1_1_language();
            $this->check_3_3_1_errorIdentification();
            $this->check_4_1_2_nameRoleValue();
            $this->check_1_3_2_meaningfulSequence();
            $this->check_1_4_4_resizeText();

            // 5. Persist issues
            $this->persistIssues($runId);

            // 6. Calculate score and update run
            $score = $this->calculateScore();
            $this->finaliseRun($runId, $score);

            // 7. Save trend snapshot
            $this->saveTrend($projectId, $runId, $score);

            return ['success' => true, 'run_id' => $runId, 'score' => $score, 'issues' => count($this->issues)];

        } catch (\Exception $e) {
            $this->markFailed($runId, $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── HTTP Fetch ─────────────────────────────────────────

    // Follows up to 3 redirects itself, checking every address is a safe public site
    private function fetchHtml(string $url): string|false {
        for ($hop = 0; $hop <= 3; $hop++) {
            if (!isSafePublicUrl($url)) return false;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,  // we follow redirects ourselves
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_USERAGENT      => 'WebAudit/1.0 (Accessibility audit tool)',
                CURLOPT_SSL_VERIFYPEER => true,   // check the site's certificate
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body   = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $next   = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);

            if ($body === false) return false;

            if ($status >= 300 && $status < 400 && $next) {
                $url = $next;     // check the new address on the next loop
                continue;
            }
            return ($status >= 200 && $status < 300) ? $body : false;
        }
        return false;              // too many redirects
    }

    // ── WCAG Rule Implementations ──────────────────────────

    /** 1.1.1 — Images missing alt attributes */
    private function check_1_1_1_nonTextContent(): void {
        $images = $this->xpath->query('//img');
        foreach ($images as $img) {
            if (!$img->hasAttribute('alt')) {
                $this->addIssue('1.1.1', 'critical', 'img',
                    $this->dom->saveHTML($img),
                    'img[src="' . $img->getAttribute('src') . '"]',
                    'Image is missing an alt attribute entirely.',
                    'Add alt="" for decorative images or a descriptive alt="..." for informative ones.'
                );
            } elseif (
                strlen($img->getAttribute('alt')) > 0 &&
                strlen($img->getAttribute('alt')) > 150
            ) {
                $this->addIssue('1.1.1', 'moderate', 'img',
                    $this->dom->saveHTML($img), '',
                    'Alt text is unusually long (>150 chars) and may not be useful.',
                    'Shorten alt text to a concise description (under 150 characters).'
                );
            }
        }
    }

    /** 1.3.1 — Missing form labels, table headers */
    private function check_1_3_1_infoRelationships(): void {
        // Inputs without labels
        $inputs = $this->xpath->query('//input[@type!="hidden" and @type!="submit" and @type!="button"]');
        foreach ($inputs as $input) {
            $id    = $input->getAttribute('id');
            $label = $id ? $this->xpath->query("//label[@for='$id']") : null;
            if (!$id || !$label || $label->length === 0) {
                if (!$input->hasAttribute('aria-label') && !$input->hasAttribute('aria-labelledby')) {
                    $this->addIssue('1.3.1', 'serious', 'input',
                        $this->dom->saveHTML($input), '',
                        'Form input has no associated <label>, aria-label, or aria-labelledby.',
                        'Add a <label for="inputId"> element or aria-label attribute to the input.'
                    );
                }
            }
        }
        // Tables without headers
        $tables = $this->xpath->query('//table');
        foreach ($tables as $table) {
            $th = $this->xpath->query('.//th', $table);
            if ($th->length === 0) {
                $this->addIssue('1.3.1', 'serious', 'table',
                    substr($this->dom->saveHTML($table), 0, 200) . '...',
                    'table',
                    'Data table has no <th> header cells.',
                    'Add <th scope="col"> or <th scope="row"> to identify header cells.'
                );
            }
        }
    }

    /** 1.3.2 — Positive tabindex values disrupt reading order */
    private function check_1_3_2_meaningfulSequence(): void {
        $elements = $this->xpath->query('//*[@tabindex > 0]');
        foreach ($elements as $el) {
            $this->addIssue('1.3.2', 'moderate', $el->tagName,
                $this->dom->saveHTML($el), '',
                'tabindex="' . $el->getAttribute('tabindex') . '" — positive tabindex values override natural focus order.',
                'Use tabindex="0" to make elements focusable without disrupting sequence, or tabindex="-1" to remove from sequence.'
            );
        }
    }

    /** 1.4.3 — Heuristic check for very light text (full contrast needs browser rendering) */
    private function check_1_4_3_contrast(): void {
        // Check inline styles for obvious contrast failures
        $elements = $this->xpath->query('//*[@style]');
        foreach ($elements as $el) {
            $style = strtolower($el->getAttribute('style'));
            // Flag white-on-white or light-on-light patterns
            if (
                (str_contains($style, 'color:#fff') || str_contains($style, 'color: #fff') || str_contains($style, 'color:white')) &&
                (str_contains($style, 'background:#fff') || str_contains($style, 'background: white') || str_contains($style, 'background-color:#fff'))
            ) {
                $this->addIssue('1.4.3', 'critical', $el->tagName,
                    substr($this->dom->saveHTML($el), 0, 200),
                    '',
                    'Inline style suggests white text on white background — likely contrast failure.',
                    'Ensure text-to-background contrast ratio is at least 4.5:1. Use a contrast checker tool.'
                );
            }
            // Flag font-size below 10px
            if (preg_match('/font-size:\s*([0-9.]+)px/', $style, $m) && (float)$m[1] < 10) {
                $this->addIssue('1.4.3', 'serious', $el->tagName,
                    substr($this->dom->saveHTML($el), 0, 200),
                    '',
                    'Inline font-size of ' . $m[1] . 'px is too small to read and will likely fail contrast requirements.',
                    'Set a minimum font size of 16px for body text. Never go below 12px for any text.'
                );
            }
        }
    }

    /** 1.4.4 — Fixed px font sizes */
    private function check_1_4_4_resizeText(): void {
        $elements = $this->xpath->query('//*[@style]');
        $flagged  = 0;
        foreach ($elements as $el) {
            $style = $el->getAttribute('style');
            if (preg_match('/font-size:\s*\d+px/', $style) && $flagged < 5) {
                $this->addIssue('1.4.4', 'minor', $el->tagName,
                    substr($this->dom->saveHTML($el), 0, 150), '',
                    'Inline fixed px font size detected — may prevent users from resizing text.',
                    'Use rem or em units for font sizes to respect user browser settings.'
                );
                $flagged++;
            }
        }
    }

    /** 1.4.10 — Viewport meta preventing zoom */
    private function check_1_4_10_reflow(): void {
        $metas = $this->xpath->query('//meta[@name="viewport"]');
        foreach ($metas as $meta) {
            $content = strtolower($meta->getAttribute('content'));
            if (
                str_contains($content, 'user-scalable=no') ||
                str_contains($content, 'user-scalable=0') ||
                (preg_match('/maximum-scale=([0-9.]+)/', $content, $m) && (float)$m[1] < 2)
            ) {
                $this->addIssue('1.4.10', 'serious', 'meta',
                    $this->dom->saveHTML($meta), 'meta[name="viewport"]',
                    'Viewport meta tag disables user zoom or limits max-scale.',
                    'Remove user-scalable=no and set maximum-scale to at least 5.0 to allow pinch-to-zoom.'
                );
            }
        }
    }

    /** 2.1.1 — onClick handlers without keyboard equivalents */
    private function check_2_1_1_keyboard(): void {
        $divClicks = $this->xpath->query('//*[self::div or self::span][@onclick and not(@tabindex) and not(@role)]');
        foreach ($divClicks as $el) {
            $this->addIssue('2.1.1', 'serious', $el->tagName,
                substr($this->dom->saveHTML($el), 0, 200), '',
                '<' . $el->tagName . '> with onclick has no tabindex or ARIA role — keyboard users cannot activate it.',
                'Use a <button> element instead of a div/span with onclick. If div is unavoidable, add role="button", tabindex="0", and a keydown handler.'
            );
        }
    }

    /** 2.4.1 — Skip navigation link */
    private function check_2_4_1_skipNav(): void {
        $first = $this->xpath->query('(//a)[1]');
        if ($first->length > 0) {
            $href = $first->item(0)->getAttribute('href');
            $text = strtolower(trim($first->item(0)->textContent));
            $isSkip = str_contains($text, 'skip') || str_contains($text, 'main') || str_starts_with($href, '#');
            if (!$isSkip) {
                $this->addIssue('2.4.1', 'moderate', 'a',
                    '', 'body > a:first-of-type',
                    'First link on page does not appear to be a skip navigation link.',
                    'Add <a href="#main-content" class="skip-link">Skip to main content</a> as the first element in <body>.'
                );
            }
        }
    }

    /** 2.4.2 — Page title */
    private function check_2_4_2_pageTitle(): void {
        $title = $this->xpath->query('//title');
        if ($title->length === 0 || trim($title->item(0)->textContent) === '') {
            $this->addIssue('2.4.2', 'critical', 'title',
                '', 'head > title',
                'Page is missing a <title> element.',
                'Add a descriptive <title> tag inside <head>. Each page should have a unique, meaningful title.'
            );
        }
    }

    /** 2.4.4 — Generic link text */
    private function check_2_4_4_linkPurpose(): void {
        $genericPhrases = ['click here', 'here', 'read more', 'more', 'link', 'this link', 'learn more'];
        $links = $this->xpath->query('//a');
        foreach ($links as $link) {
            $text = strtolower(trim($link->textContent));
            if (in_array($text, $genericPhrases) && !$link->hasAttribute('aria-label')) {
                $this->addIssue('2.4.4', 'serious', 'a',
                    $this->dom->saveHTML($link), '',
                    'Link text "' . htmlspecialchars($text) . '" is non-descriptive.',
                    'Use descriptive link text that explains the destination, e.g. "Download 2024 annual report (PDF)". If short text is needed, add aria-label with full context.'
                );
            }
        }
    }

    /** 2.4.6 — Empty or duplicate headings */
    private function check_2_4_6_headings(): void {
        $headings = $this->xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');
        $seen     = [];
        foreach ($headings as $h) {
            $text = trim($h->textContent);
            if ($text === '') {
                $this->addIssue('2.4.6', 'serious', $h->tagName,
                    $this->dom->saveHTML($h), '',
                    'Empty heading element <' . $h->tagName . '> detected.',
                    'Remove empty headings or add descriptive text content.'
                );
            } elseif (isset($seen[$text])) {
                $this->addIssue('2.4.6', 'minor', $h->tagName,
                    $this->dom->saveHTML($h), '',
                    'Duplicate heading text "' . htmlspecialchars($text) . '" — screen reader users navigating by headings may be confused.',
                    'Make heading text unique or differentiate with hidden context via <span class="sr-only">.'
                );
            }
            $seen[$text] = true;
        }
    }

    /** 2.4.7 — Outline: none without replacement */
    private function check_2_4_7_focusVisible(): void {
        $elements = $this->xpath->query('//*[@style]');
        foreach ($elements as $el) {
            $style = strtolower($el->getAttribute('style'));
            if (str_contains($style, 'outline: none') || str_contains($style, 'outline:none') || str_contains($style, 'outline: 0')) {
                $this->addIssue('2.4.7', 'serious', $el->tagName,
                    substr($this->dom->saveHTML($el), 0, 200), '',
                    'Inline outline:none detected — removes visible keyboard focus indicator.',
                    'Never remove the focus outline without providing an equivalent styled replacement using :focus-visible CSS.'
                );
            }
        }
    }

    /** 3.1.1 — Missing or invalid lang attribute */
    private function check_3_1_1_language(): void {
        $html = $this->xpath->query('//html[@lang]');
        if ($html->length === 0) {
            $this->addIssue('3.1.1', 'serious', 'html',
                '', 'html',
                'The <html> element is missing a lang attribute.',
                'Add a valid BCP 47 language tag, e.g. <html lang="en">.'
            );
        }
    }

    /** 3.3.1 — Form error handling */
    private function check_3_3_1_errorIdentification(): void {
        $requiredInputs = $this->xpath->query('//input[@required or @aria-required="true"]');
        foreach ($requiredInputs as $input) {
            if (!$input->hasAttribute('aria-describedby') && !$input->hasAttribute('aria-errormessage')) {
                $this->addIssue('3.3.1', 'moderate', 'input',
                    $this->dom->saveHTML($input), '',
                    'Required input has no aria-describedby or aria-errormessage linking it to an error message.',
                    'Add aria-describedby="errorId" to the input and a corresponding <span id="errorId" role="alert"> for error messages.'
                );
            }
        }
    }

    /** 4.1.2 — ARIA roles and button labels */
    private function check_4_1_2_nameRoleValue(): void {
        // Buttons without accessible names
        $buttons = $this->xpath->query('//button');
        foreach ($buttons as $btn) {
            $text  = trim($btn->textContent);
            $label = $btn->getAttribute('aria-label');
            $labelledby = $btn->getAttribute('aria-labelledby');
            if ($text === '' && $label === '' && $labelledby === '') {
                $this->addIssue('4.1.2', 'critical', 'button',
                    $this->dom->saveHTML($btn), '',
                    'Button has no accessible name (empty text, no aria-label, no aria-labelledby).',
                    'Add descriptive text content or an aria-label attribute to every <button>.'
                );
            }
        }
        // Invalid ARIA roles
        $validRoles = ['alert','alertdialog','application','article','banner','button','cell','checkbox',
            'columnheader','combobox','complementary','contentinfo','definition','dialog','directory',
            'document','feed','figure','form','grid','gridcell','group','heading','img','link','list',
            'listbox','listitem','log','main','marquee','math','menu','menubar','menuitem','menuitemcheckbox',
            'menuitemradio','navigation','none','note','option','presentation','progressbar','radio',
            'radiogroup','region','row','rowgroup','rowheader','scrollbar','search','searchbox','separator',
            'slider','spinbutton','status','switch','tab','table','tablist','tabpanel','term','textbox',
            'timer','toolbar','tooltip','tree','treegrid','treeitem'];
        $roledElements = $this->xpath->query('//*[@role]');
        foreach ($roledElements as $el) {
            $role = strtolower(trim($el->getAttribute('role')));
            if (!in_array($role, $validRoles)) {
                $this->addIssue('4.1.2', 'serious', $el->tagName,
                    substr($this->dom->saveHTML($el), 0, 200), '',
                    'Invalid ARIA role="' . htmlspecialchars($role) . '" — not a recognised ARIA role.',
                    'Replace with a valid WAI-ARIA role from the ARIA specification.'
                );
            }
        }
    }

    // ── Helpers ────────────────────────────────────────────

    private function addIssue(
        string $ruleCode, string $severity, string $elementType,
        string $html, string $selector, string $context, string $fix
    ): void {
        $this->issues[] = [
            'rule_code'    => $ruleCode,
            'severity'     => $severity,
            'element_type' => $elementType,
            'element_html' => substr($html, 0, 500),
            'selector'     => substr($selector, 0, 500),
            'context'      => $context,
            'fix_suggestion' => $fix,
        ];
    }

    private function createRun(int $projectId, int $userId, string $url): int {
        $stmt = $this->db->prepare(
            'INSERT INTO audit_runs (project_id, user_id, url_audited, status, started_at)
             VALUES (?, ?, ?, "running", NOW())'
        );
        $stmt->execute([$projectId, $userId, $url]);
        return (int)$this->db->lastInsertId();
    }

    private function persistIssues(int $runId): void {
        // Cache rule IDs
        $ruleMap = [];
        $rows = $this->db->query('SELECT id, rule_code FROM wcag_rules')->fetchAll();
        foreach ($rows as $r) $ruleMap[$r['rule_code']] = $r['id'];

        $stmt = $this->db->prepare(
            'INSERT INTO audit_issues
             (run_id, rule_id, severity, element_type, element_html, selector, context, fix_suggestion)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($this->issues as $issue) {
            $ruleId = $ruleMap[$issue['rule_code']] ?? null;
            if (!$ruleId) continue;
            $stmt->execute([
                $runId,
                $ruleId,
                $issue['severity'],
                $issue['element_type'],
                $issue['element_html'],
                $issue['selector'],
                $issue['context'],
                $issue['fix_suggestion'],
            ]);
        }
    }

       // Each issue deducts points by severity, but one rule can deduct at most
    // three times its weight, so one repeated mistake (e.g. 30 images without
    // alt text) can't wipe out the whole score on its own.
    private function calculateScore(): int {
        if (empty($this->issues)) return 100;
        $weights = ['critical' => 20, 'serious' => 10, 'moderate' => 5, 'minor' => 2];
        $perRule = [];
        foreach ($this->issues as $issue) {
            $w   = $weights[$issue['severity']] ?? 0;
            $key = $issue['rule_code'] . '|' . $issue['severity'];
            $perRule[$key] = min(($perRule[$key] ?? 0) + $w, $w * 3);
        }
        return max(0, 100 - array_sum($perRule));
    }

    private function finaliseRun(int $runId, int $score): void {
        $counts = ['critical'=>0,'serious'=>0,'moderate'=>0,'minor'=>0];
        foreach ($this->issues as $i) $counts[$i['severity']]++;
        $stmt = $this->db->prepare(
            'UPDATE audit_runs SET
               status="completed", score=?, total_issues=?,
               critical_count=?, serious_count=?, moderate_count=?, minor_count=?,
               completed_at=NOW()
             WHERE id=?'
        );
        $stmt->execute([
            $score, count($this->issues),
            $counts['critical'], $counts['serious'], $counts['moderate'], $counts['minor'],
            $runId
        ]);
    }

    private function saveTrend(int $projectId, int $runId, int $score): void {
        $counts = ['critical'=>0,'serious'=>0];
        foreach ($this->issues as $i) {
            if (isset($counts[$i['severity']])) $counts[$i['severity']]++;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO score_trends (project_id, run_id, score, critical_count, serious_count)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$projectId, $runId, $score, $counts['critical'], $counts['serious']]);
    }

    private function markFailed(int $runId, string $error): void {
        $stmt = $this->db->prepare('UPDATE audit_runs SET status="failed", completed_at=NOW() WHERE id=?');
        $stmt->execute([$runId]);
        error_log("Audit failed for run $runId: $error");
    }
}
