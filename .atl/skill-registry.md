# Skill Registry — jedomi

Last updated: 2026-05-21

## Moodle Plugin Skills

These skills are relevant for Moodle plugin development (local_jedomi is a `local` plugin):

| Skill | Location | Trigger |
|-------|----------|---------|
| moodle-plugin-coding | `~/.config/opencode/skills/moodle-plugin-coding/` | PHP plugin files, db, settings, classes, renderers, phpdocs |
| moodle-mustache | `~/.config/opencode/skills/moodle-mustache/` | .mustache templates |
| moodle-javascript | `~/.config/opencode/skills/moodle-javascript/` | amd/src, JS in Mustache/PHP, Grunt builds |
| moodle-rendering | `~/.config/opencode/skills/moodle-rendering/` | Renderers, renderables, CSS/styles.css |
| moodle-webservices | `~/.config/opencode/skills/moodle-webservices/` | db/services.php, classes/external/* |
| moodle-phpunit | `~/.config/opencode/skills/moodle-phpunit/` | PHPUnit tests |
| moodle-behat | `~/.config/opencode/skills/moodle-behat/` | .feature tests, acceptance suites |
| moodle-changelog | `~/.config/opencode/skills/moodle-changelog/` | CHANGES.md edits, PR changelog updates |
| moodle-pr | `~/.config/opencode/skills/moodle-pr/` | PR creation, .github/workflows, CODEOWNERS |
| moodle-docs | `~/.config/opencode/skills/moodle-docs/` | Documentation for Moodle features |
| moodle-local-pluginskel | `~/.config/opencode/skills/moodle-local-pluginskel/` | Plugin scaffolding with pluginskel |
| moodle-release | `~/.config/opencode/skills/moodle-release/` | Plugin release, tag creation |
| moodle-workplace-sync | `~/.config/opencode/skills/moodle-workplace-sync/` | Pass changes to Workplace branches |

## Workflow & Automation Skills

| Skill | Location | Trigger |
|-------|----------|---------|
| plugin-commit | `~/.config/opencode/skills/plugin-commit/` | Commits, conventional-commits |
| plugin-ci-preflight | `~/.config/opencode/skills/plugin-ci-preflight/` | Pre-commit/pre-push validation |
| branch-pr | `~/.config/opencode/skills/branch-pr/` | PR creation, changes review |
| issue-creation | `~/.config/opencode/skills/issue-creation/` | GitHub issue, bug report, feature request |
| judgment-day | `~/.config/opencode/skills/judgment-day/` | "judgment day", adversarial review |
| skill-creator | `~/.config/opencode/skills/skill-creator/` | New skill creation, agent instructions |
| skill-sync | `~/.config/opencode/skills/skill-sync/` | Skill sync to global dir |

## UI / Design Skills

| Skill | Location | Trigger |
|-------|----------|---------|
| baseline-ui | `~/.agents/skills/baseline-ui/` | Tailwind UI components, CSS utilities, design consistency |
| fixing-accessibility | `~/.agents/skills/fixing-accessibility/` | ARIA, keyboard nav, focus, WCAG compliance |
| fixing-metadata | `~/.agents/skills/fixing-metadata/` | SEO meta, OG tags, structured data |
| fixing-motion-performance | `~/.agents/skills/fixing-motion-performance/` | Animation performance, layout thrashing |

## TDD & Testing Skills

| Skill | Location | Trigger |
|-------|----------|---------|
| tdd | `~/.config/opencode/skills/tdd/` | Moodle TDD, feature/bugfix/refactor (MANDATORY) |
| go-testing | `~/.config/opencode/skills/go-testing/` | Go tests, Bubbletea TUI testing |

## SDD Skills (orchestrator-managed)

| Skill | Location |
|-------|----------|
| sdd-init | `~/.config/opencode/skills/sdd-init/` |
| sdd-explore | `~/.config/opencode/skills/sdd-explore/` |
| sdd-propose | `~/.config/opencode/skills/sdd-propose/` |
| sdd-spec | `~/.config/opencode/skills/sdd-spec/` |
| sdd-design | `~/.config/opencode/skills/sdd-design/` |
| sdd-tasks | `~/.config/opencode/skills/sdd-tasks/` |
| sdd-apply | `~/.config/opencode/skills/sdd-apply/` |
| sdd-verify | `~/.config/opencode/skills/sdd-verify/` |
| sdd-archive | `~/.config/opencode/skills/sdd-archive/` |
| sdd-onboard | `~/.config/opencode/skills/sdd-onboard/` |

## Other Skills

| Skill | Location | Trigger |
|-------|----------|---------|
| find-skills | `~/.agents/skills/find-skills/` | "how do I do X", "find a skill" |

---

## Project Conventions

No project-level convention files found (no AGENTS.md, CLAUDE.md, .cursorrules, GEMINI.md).
