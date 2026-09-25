# Pest Plugin PAO Mutation

Pest mutation results as compact JSON for [Laravel PAO](https://github.com/laravel/pao).

## Requirements

- PHP 8.4+, Pest 5, Pest Mutate 5, and Laravel PAO 1.1+

## Installation

```bash
composer require --dev wheesnoza/pest-plugin-pao-mutation
```

## Usage

With PAO active, run:

```bash
./vendor/bin/pest --mutate --min=80
```

## Before and after

Illustrative example: two passing tests miss one of two mutations.

**Before — PAO without this plugin (abbreviated):**

```json
{
    "tool": "pest",
    "result": "passed",
    "tests": 2,
    "passed": 2,
    "raw": [
        "UNTESTED app/Http/TodoController.php > Line 44: ReturnValue",
        "Mutations: 1 untested, 1 tested",
        "Score: 50.00%",
        "FAIL Mutation score below expected: 50.0 %. Minimum: 80.0 %."
    ]
}
```

**After — PAO with this plugin** (one line in actual output, formatted here):

```json
{
    "tool": "pest-mutate",
    "result": "failed",
    "score": 50,
    "mutations": 2,
    "tested": 1,
    "untested": 1,
    "untested_details": [
        {
            "file": "app/Http/TodoController.php",
            "line": 44,
            "mutator": "ReturnValue",
            "diff": {
                "original": "return Todo::all()->toArray();",
                "mutated": "return [];"
            }
        }
    ]
}
```

## Measured output

Full suite: 183 tests, 22 mutated files, 652 mutations.

- PAO alone: 2,162 tokens
- PAO with this plugin: 36 tokens (**98.3% fewer**)

Both runs used the same full-suite Pest mutation command with PAO active; only this plugin was toggled. We counted both JSON outputs with OpenAI's [tiktoken](https://github.com/openai/tiktoken) using `o200k_base`.
