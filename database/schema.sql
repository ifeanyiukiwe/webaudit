-- ============================================================
-- WebAudit Pro — Accessibility Auditing Platform
-- Database Schema (MySQL 8.0+)
-- ============================================================

CREATE DATABASE IF NOT EXISTS webaudit_pro
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE webaudit_pro;

-- ──────────────────────────────────────────────
-- Users
-- ──────────────────────────────────────────────
CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120)  NOT NULL,
  email         VARCHAR(180)  NOT NULL UNIQUE,
  password_hash VARCHAR(255)  NOT NULL,
  role          ENUM('admin','auditor','viewer') NOT NULL DEFAULT 'auditor',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ──────────────────────────────────────────────
-- Projects (a website being tracked over time)
-- ──────────────────────────────────────────────
CREATE TABLE projects (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  name          VARCHAR(200) NOT NULL,
  base_url      VARCHAR(500) NOT NULL,
  description   TEXT,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ──────────────────────────────────────────────
-- Audit Runs
-- ──────────────────────────────────────────────
CREATE TABLE audit_runs (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id    INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  url_audited   VARCHAR(500) NOT NULL,
  status        ENUM('pending','running','completed','failed') DEFAULT 'pending',
  score         TINYINT UNSIGNED,          -- 0-100 composite score
  total_issues  SMALLINT UNSIGNED DEFAULT 0,
  critical_count SMALLINT UNSIGNED DEFAULT 0,
  serious_count  SMALLINT UNSIGNED DEFAULT 0,
  moderate_count SMALLINT UNSIGNED DEFAULT 0,
  minor_count    SMALLINT UNSIGNED DEFAULT 0,
  started_at    TIMESTAMP NULL,
  completed_at  TIMESTAMP NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)    REFERENCES users(id)
) ENGINE=InnoDB;

-- ──────────────────────────────────────────────
-- WCAG Rules catalogue
-- ──────────────────────────────────────────────
CREATE TABLE wcag_rules (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_code     VARCHAR(20)  NOT NULL UNIQUE,  -- e.g. '1.1.1'
  rule_name     VARCHAR(120) NOT NULL,
  principle     ENUM('Perceivable','Operable','Understandable','Robust') NOT NULL,
  level         ENUM('A','AA','AAA') NOT NULL,
  description   TEXT,
  how_to_fix    TEXT
) ENGINE=InnoDB;

-- ──────────────────────────────────────────────
-- Issues found per audit run
-- ──────────────────────────────────────────────
CREATE TABLE audit_issues (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id        INT UNSIGNED NOT NULL,
  rule_id       INT UNSIGNED NOT NULL,
  severity      ENUM('critical','serious','moderate','minor') NOT NULL,
  element_type  VARCHAR(50),               -- e.g. 'img', 'button', 'form'
  element_html  TEXT,                      -- snippet of offending HTML
  selector      VARCHAR(500),              -- CSS selector to locate element
  context       TEXT,                      -- surrounding text/context
  fix_suggestion TEXT,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (run_id)  REFERENCES audit_runs(id)  ON DELETE CASCADE,
  FOREIGN KEY (rule_id) REFERENCES wcag_rules(id)
) ENGINE=InnoDB;

-- ──────────────────────────────────────────────
-- Trend snapshots (pre-aggregated for charts)
-- ──────────────────────────────────────────────
CREATE TABLE score_trends (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id    INT UNSIGNED NOT NULL,
  run_id        INT UNSIGNED NOT NULL,
  score         TINYINT UNSIGNED,
  critical_count SMALLINT UNSIGNED DEFAULT 0,
  serious_count  SMALLINT UNSIGNED DEFAULT 0,
  recorded_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (run_id)     REFERENCES audit_runs(id)
) ENGINE=InnoDB;

-- ──────────────────────────────────────────────
-- Seed: WCAG 2.1 AA Rules (25 core rules)
-- ──────────────────────────────────────────────
INSERT INTO wcag_rules (rule_code, rule_name, principle, level, description, how_to_fix) VALUES
('1.1.1','Non-text Content','Perceivable','A','All non-text content has a text alternative.','Add descriptive alt attributes to all <img> tags. For decorative images use alt="".'),
('1.2.1','Audio-only / Video-only','Perceivable','A','Provide alternatives for time-based media.','Provide a transcript for audio-only content and a text alternative for video-only.'),
('1.3.1','Info and Relationships','Perceivable','A','Structure conveyed through presentation can be programmatically determined.','Use semantic HTML elements (headings, lists, tables with headers).'),
('1.3.2','Meaningful Sequence','Perceivable','A','Correct reading sequence can be programmatically determined.','Ensure DOM order reflects visual reading order. Avoid CSS-only reordering.'),
('1.3.3','Sensory Characteristics','Perceivable','A','Instructions do not rely solely on shape, colour, size or position.','Supplement sensory references with text labels.'),
('1.4.1','Use of Colour','Perceivable','A','Colour is not the only visual means of conveying information.','Always pair colour cues with icons, patterns, or text labels.'),
('1.4.2','Audio Control','Perceivable','A','Provide a mechanism to pause or stop audio that auto-plays.','Add visible play/pause controls to any auto-playing audio.'),
('1.4.3','Contrast (Minimum)','Perceivable','AA','Text has a contrast ratio of at least 4.5:1 (3:1 for large text).','Use a contrast checker and adjust foreground/background colour pairs.'),
('1.4.4','Resize Text','Perceivable','AA','Text can be resized up to 200% without loss of content.','Use relative units (rem, em, %) instead of fixed px for font sizes.'),
('1.4.5','Images of Text','Perceivable','AA','Use text rather than images of text wherever possible.','Replace text-in-images with real HTML text styled with CSS.'),
('1.4.10','Reflow','Perceivable','AA','Content can be presented without horizontal scrolling at 320px width.','Use responsive layouts; avoid fixed-width containers.'),
('1.4.11','Non-text Contrast','Perceivable','AA','UI components and graphical objects have a contrast ratio of at least 3:1.','Ensure borders on inputs, icons, and focus indicators meet 3:1 contrast.'),
('1.4.12','Text Spacing','Perceivable','AA','No loss of content when text spacing is adjusted.','Avoid fixed heights on containers holding text.'),
('2.1.1','Keyboard','Operable','A','All functionality available via keyboard.','Ensure every interactive element is focusable and operable with keyboard.'),
('2.1.2','No Keyboard Trap','Operable','A','Keyboard focus is never locked.','Ensure modals and widgets allow focus to escape.'),
('2.4.1','Bypass Blocks','Operable','A','A mechanism exists to skip repeated blocks.','Add a skip-to-main-content link as the first focusable element.'),
('2.4.2','Page Titled','Operable','A','Pages have descriptive titles.','Provide a unique, descriptive <title> for every page.'),
('2.4.3','Focus Order','Operable','A','Focus order preserves meaning and operability.','Ensure tabindex values reflect logical reading order.'),
('2.4.4','Link Purpose','Operable','A','Purpose of each link can be determined from link text or context.','Avoid generic link text like "click here". Use descriptive labels.'),
('2.4.6','Headings and Labels','Operable','AA','Headings and labels are descriptive.','Use unique, meaningful heading text. Avoid duplicate headings.'),
('2.4.7','Focus Visible','Operable','AA','Any keyboard operable interface has visible focus indicator.','Never suppress the default outline without providing a styled alternative.'),
('3.1.1','Language of Page','Understandable','A','Default language of page can be programmatically determined.','Set a valid lang attribute on the <html> element.'),
('3.2.1','On Focus','Understandable','A','Receiving focus does not trigger unexpected context change.','Avoid onFocus handlers that navigate away or submit forms.'),
('3.3.1','Error Identification','Understandable','A','Input errors are identified and described in text.','Display descriptive error messages linked to the specific field via aria-describedby.'),
('4.1.2','Name, Role, Value','Robust','A','All UI components have accessible name, role, and value.','Use semantic HTML; add aria-label or aria-labelledby where native semantics are insufficient.');


-- ──────────────────────────────────────────────
-- Seed: demo user and default project (the front end uses id 1 for both)
-- ──────────────────────────────────────────────
INSERT INTO users (name, email, password_hash, role)
VALUES ('Demo User', 'demo@example.com', 'not-used', 'admin');

INSERT INTO projects (user_id, name, base_url)
VALUES (1, 'Default Project', 'https://example.com');