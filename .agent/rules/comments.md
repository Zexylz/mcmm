---
trigger: always_on
---

# rule.md — antigravity (Google) Documentation & Comment Generation Rules
Version: 1.0
Scope: Web Development languages (TS/JS, Python, PHP, C#, Java/Kotlin, CSS/SCSS, HTML, XML, JSON, SQL)
Purpose: Generate high-quality documentation comments for functions/classes/modules and KSS for stylesheets.

## 0) Global Goals
- Produce accurate, concise, developer-friendly documentation comments.
- Do NOT invent behavior. If behavior is unclear, document assumptions explicitly as "Assumption:" or keep wording generic.
- Prefer describing "what" and "why" over restating "how" (unless non-obvious).
- Keep comments up-to-date with signature and return types.
- Avoid redundant comments (e.g., `i++ // increment i`).

## 1) When to Generate Comments
Generate documentation comments for:
- Public APIs: exported functions, public methods, public classes, modules.
- Non-trivial private functions: complex logic, tricky edge-cases, performance considerations.
- Data structures: DTOs/models, config objects, schema definitions.
- UI components: props/inputs, emitted events, side effects, accessibility notes.
- Stylesheets: blocks/components/utilities in CSS/SCSS via KSS.

Do NOT generate doc comments for:
- Self-explanatory 1–3 line helpers with obvious names.
- Re-export barrels unless adding package-level docs.
- Generated code.

## 2) Comment Content Standard
Each doc comment should cover (when relevant):
- Summary: one sentence, imperative mood (e.g., "Parse...", "Render...", "Compute...").
- Context/Intent: short rationale if helpful.
- Parameters: meaning, constraints, default behavior.
- Returns: meaning and shape.
- Throws/Errors: exceptions, error conditions, error objects.
- Side effects: I/O, state mutations, DOM, network, caching.
- Performance: big-O or hotspots when non-trivial.
- Security: validation, sanitization, auth assumptions.
- Examples: only when usage is not obvious.

Tone:
- Professional, concise, neutral.
- No emojis, no jokes, no filler.

## 3) Formatting Rules (Language-Specific)

### 3.1 JavaDoc (Java/Kotlin style)
Use `/** ... */` and tags:
- `@param name description`
- `@return description`
- `@throws ExceptionType condition`
- `@see` when referencing related APIs
Example structure:
- First line summary
- Optional blank line
- Detailed notes
- Tags at bottom

### 3.2 XML Documentation (C# / .NET)
Use `///` with:
- `<summary>...</summary>`
- `<param name="x">...</param>`
- `<returns>...</returns>`
- `<exception cref="...">...</exception>`
- `<remarks>...</remarks>` for nuance
- `<example>...</example>` if needed

### 3.3 Python Docstrings (PEP 257)
Use triple quotes `""" ... """` directly under def/class.
Default style: **Google-style** docstrings.
Sections:
- Args:
- Returns:
- Raises:
- Examples: (optional)

### 3.4 PHPDoc
Use `/** ... */`
- `@param Type $name description`
- `@return Type description`
- `@throws ExceptionType description`
- `@template` / `@psalm-` / `@phpstan-` tags only if codebase uses them (otherwise omit).

### 3.5 TypeDoc (TypeScript/JavaScript)
Use `/** ... */`
- `@param name description`
- `@returns description`
- `@throws` if relevant
- `@remarks` for edge cases
- `@example` only when needed
If generics are used, document type parameters with `@typeParam`.

### 3.6 KSS (Knyle Style Sheets) for CSS/SCSS
Use KSS blocks:
- Description (what component does)
- Markup example (HTML snippet)
- Styleguide reference (e.g. `Styleguide 2.1.0`)
Include variants with modifiers (e.g. `.btn--primary`).

## 4) Output Selection (Pick the Right Style)
Choose the doc format based on file type and language:
- `.java`, `.kt` -> JavaDoc
- `.cs` -> XML Documentation
- `.py` -> Python docstrings
- `.php` -> PHPDoc
- `.ts`, `.tsx`, `.js`, `.jsx` -> TypeDoc-style JSDoc
- `.css`, `.scss`, `.sass`, `.less` -> KSS
- `.html`, `.xml` -> short block comments only when needed (describe sections/components)
- `.json`, `.yml`, `.yaml` -> comments only if format supports it; otherwise document externally

If language is ambiguous:
- Prefer TypeDoc/JSDoc for JS-family.
- Prefer concise block comments over wrong doc syntax.

## 5) Comment Placement
- Place documentation comments immediately above the symbol they document.
- For Python: docstring is the first statement inside the function/class.
- For C#: XML docs directly above member.
- For KSS: block directly above the CSS rule it documents.

## 6) Behavioral Constraints (No Hallucinations)
- Never claim a function validates, sanitizes, caches, or authorizes unless code shows it.
- Never add exceptions/throws docs unless code actually throws or returns error.
- If the code calls external APIs, mention them generically unless endpoint/SDK is explicit.

## 7) Naming & Terminology
- Use domain vocabulary found in code (props names, model names, API terms).
- Use consistent terminology for the same concept across files.
- Prefer "id" vs "ID" based on existing project convention.

## 8) Examples Policy
Include an example only if:
- The function is part of a public API AND usage is not obvious; OR
- There are tricky defaults/edge cases.

Examples must be minimal, correct, and match the actual signature.

## 9) KSS Template Library

### 9.1 Component Template
/*
Button
A clickable button component used for actions.

Markup:
<button class="btn {{modifier_class}}">Label</button>

Modifiers:
.btn--primary  - Primary emphasis
.btn--danger   - Destructive action

Styleguide 2.1.0
*/

### 9.2 Layout Template
/*
Grid
Responsive grid utilities.

Markup:
<div class="grid grid--2">
  <div class="grid__item">A</div>
  <div class="grid__item">B</div>
</div>

Styleguide 3.2.0
*/

## 10) Doc Templates (Quick Reference)

### TypeDoc / JSDoc
/**
 * Summary sentence.
 *
 * @param x - Description.
 * @returns Description.
 * @throws ErrorType - Condition. (only if applicable)
 * @remarks Edge cases / side effects. (optional)
 */

### Python (Google style)
def fn(x: int) -> int:
    """Summary sentence.

    Args:
        x: Description.

    Returns:
        Description.

    Raises:
        ValueError: Condition. (only if applicable)
    """

### PHPDoc
/**
 * Summary sentence.
 *
 * @param int $x Description.
 * @return int Description.
 * @throws InvalidArgumentException Condition. (only if applicable)
 */

### JavaDoc
/**
 * Summary sentence.
 *
 * @param x description
 * @return description
 * @throws IllegalArgumentException condition (only if applicable)
 */

### C# XML Docs
/// <summary>Summary sentence.</summary>
/// <param name="x">Description.</param>
/// <returns>Description.</returns>
/// <exception cref="System.ArgumentException">Condition. (only if applicable)</exception>

## 11) Quality Checklist (Must Pass)
- Comment matches signature (names/types/return).
- Summary is single sentence and accurate.
- No redundant restatement of code.
- No invented behaviors.
- Formatting matches language.
- Uses consistent terminology.

End of rules.