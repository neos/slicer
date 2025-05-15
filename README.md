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

Use a payload like `{"ref":"…","repository":{"clone_url":"https://github.com/…"}}`
and replace `ref` and `clone_url` values as needed:

`php slicer.php '{"ref":"refs/heads/8.2","repository":{"clone_url":"https://github.com/neos/flow-development-collection.git"}}'`

To split a tag, just use

`php slicer.php '{"ref":"refs/tags/1.2.3","repository":{"clone_url":"https://github.com/…"}}'`
