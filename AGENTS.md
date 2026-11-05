# pig-quant — notes for agents

Dry-run crypto trading in pure PHP: an engine (`src/Quant/`, namespace `Quant\`) and its pig
extension (`src/PigQuant/`, namespace `PigQuant\`). `README.md` says what it does; this says how
it is worked on. Keep this file under 100 lines.

## The two halves

- **`Quant\` knows nothing of pig.** No `use Pig\…` anywhere under `src/Quant/`: it does no I/O
  and reads no clock. An exchange builds the request it wants (`Transport\HttpRequest`) and
  parses what comes back; the host moves the bytes. The same package from Packagist gives
  another PHP program (a myphp app, say) `Quant\` alone.
- **`PigQuant\` is pig's side**: settings, the `/quant` command, the `quant_*` tools, the web
  panel, the stream on pig's event loop, files under `~/.pig/agent/quant/`.
- One engine per machine, however many pig processes: the first to start it owns it (`Owner`,
  a loopback port in `runner.json`); every other process forwards to it (`Hub`). Everything the
  command, the tools and the panel do goes through `Hub`, never to a `Runner` directly.

## Rules

- **A plain HTTP request goes through pig's `Pig\Ai\Http\Retrying::fetch()`.** Never write a
  retry loop at the call site; the rules live there. Exchange refusals (a 4xx, a body that is not
  candles, an error on the stream) are `Quant\Exchange\ExchangeError` and are not retried.
- No silent fallback: `catch` re-throws, or stops and shows the error (`Runner::problem()`).
  The dry run never pretends to be trading when it is not.
- A new exchange is one class implementing `Quant\Exchange\Exchange` — pure functions, tested
  from recorded payloads — and a line in `PigQuant\Config::exchange()`. A new strategy is one
  class implementing `Quant\Strategy\Strategy` and a line in `Config::strategy()`.
- The developer's name does not appear in this repository; write "the developer".
- No composer dependencies. A git-installed package is not run through Composer, so
  `extensions/pig-quant.php` registers the autoloader for both namespaces itself.
- Git (commit, tag, push) is the developer's; report which files changed.

## Tests

`PIG_DIR=~/Development/owner/pig ~/Development/owner/pig/vendor/bin/phpunit -c phpunit.xml` —
the engine's tests need nothing from pig but its PHPUnit.

## Changelog

`CHANGELOG.md` in pig's format: `## Unreleased` while working, `## [x.y.z] - YYYY-MM-DD` at
release; the version lives in the git tag only.
