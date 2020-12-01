# slicer

_Some code to keep read-only package repositories updated._

This is inspired by https://github.com/dflydev/dflydev-git-subsplit-github-webhook, thanks!

It uses `splitsh-lite` (and expects it in the path!)

## Configuration

See `config.json`.

The `allowedRefsPattern` can be given per project. If it does not match an incoming `ref`
in the payload, processing of the split is skipped.

## Setting up Jenkins

* Create parameterized job
* Have it clone slicer
* Add a string parameter called "payload"
* Add a shell build step running `php slicer.php "${payload}"`

## Manual invocation

Use a payload like `{"ref":"refs/heads/master","repository":{"url":"https://github.com/neos/flow-development-collection"}}`
and replace `ref` and `url` values as needed:

    php slicer.php '{"ref":"refs/heads/master","repository":{"url":"https://github.com/…"}}'
