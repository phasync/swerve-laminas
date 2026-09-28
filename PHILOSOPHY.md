# The Ennerd philosophy

Software you depend on for a decade should be software you can own.

- **Nothing you don't already have.** This adapter depends on swerve and on Laminas MVC, which
  your application already uses. swerve, [phasync](https://github.com/phasync/phasync) and
  [phasync-ext](https://github.com/phasync/phasync-ext) depend only on each other: no dependency
  tree to audit, no upstream to wait for, no churn you did not choose.
- **Small enough to own whole.** The adapter is a few hundred lines; swerve and phasync about
  9,500 each. Behaviour is pinned by tests and written down, so a developer, or a coding agent
  such as Claude Code or Codex, can read all of it and change any part of it with confidence.
- **Your application stays yours.** No rewrite, no framework fork: the same code runs under
  PHP-FPM and under swerve, and you can move back at any time.
- **A head start, not a vendor.** Treat this code as the first years of your own product, as if you
  had hired its author early on. The MIT licence lets you fork it, change it and keep it.
- **Measured, not claimed.** Performance claims come with the method and the raw results to
  reproduce them.
