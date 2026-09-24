# Agent Guidelines

## Comments and docblocks

Keep them minimal. Write one only when it tells the reader something the
name, signature and body don't:

- **why** the code does something, e.g. a workaround, a trade-off or a reason
  for a limit
- a **non-obvious behavior** or side effect, e.g. what a returned `bool` means
- the **format** of a value, e.g. `"app"` or `"services.db"`

Leave them out when the code already says it:

- No docblock that restates the method name, e.g. "Run the provisioning
  steps" on `provision()`, whose loop shows it runs each step.
- No `@param`, `@return` or `@var` tags just for generic types such as
  `array<int, Step>`; nothing checks them.
- No "Create a new … instance." docblocks on constructors.
- No class docblock that only repeats the class name.
- No inline comment that narrates the next line.

When one is needed, write it in full sentences and put the reason first, not a
"Get the …" summary:

```php
/**
 * Compose already reports a stopped daemon or a missing plugin clearly, and
 * checking those first would add about 160ms to every command.
 */
protected function ensureInstalled(): void
```

Wrap docblocks and comments at 78 columns.
