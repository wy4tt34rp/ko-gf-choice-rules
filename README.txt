KO – GF Choice Rules (Stable Build v2.8.0)
==========================================

Overview
--------
KO – GF Choice Rules adds front-end choice locking to Gravity Forms, with:

- Simple Rules (single trigger field/value → hide/disable one choice)
- Advanced Rules (multi-condition AND logic, including Miles/Kilometers logic)

The plugin works entirely via the WordPress admin UI:
Settings → KO GF Choice Rules

Files
-----
- ko-gf-choice-rules.php    (main plugin file, admin UI + validation + script loader)
- ko-gf-lock-frontend.js    (front-end logic, no jQuery required)
- README.txt                 (this file)

Key Concepts
------------
- Rules target specific *choice values*, not labels.
- For product/radio values like "VOLVO CERTIFIED AND EATS 3 MO/25K|1820",
  the engine only uses the base part before the pipe:
  "VOLVO CERTIFIED AND EATS 3 MO/25K"
- Anything after | (typically price) is ignored for matching.

Simple Rules
------------
Simple Rules let you configure logic such as:

"When Trigger Field equals VALUE, hide or disable a specific choice."

Columns:

- Form ID
- Trigger Field ID
- Trigger Value
- Target Field ID
- Target Value Equals (base)
- Action
  - Hide    → remove choice from UI
  - Disable → show but grey out, unselectable
- Logic Mode
  - When trigger does NOT equal Trigger Value
  - When trigger equals Trigger Value

Advanced Rules
--------------
Advanced Rules allow you to define multi-condition logic:

"When ALL conditions under a Rule Key are true, the target choice is allowed.
 If any condition fails, an action (Hide/Disable) is applied."

Columns:

- Rule Key
  - Groups rows into one rule (AND logic).
- Form ID
- Target Field ID
- Target Value Equals (base)
- Action
  - Hide / Disable
- Trigger Field ID
- Trigger Type
  - numeric / string
- Trigger Operator
  - numeric: <, <=, >, >=, =, !=
  - string: =, !=, contains
- Trigger Value
- Unit Mode
  - — (blank) → no unit conversion
  - Miles / Kilometers (miles_km) → converts KM to Miles
- Unit Field ID
  - Field that stores the unit (e.g. "Miles"/"Kilometers")

Miles / Kilometers Behavior
---------------------------
When Unit Mode is set to Miles / Kilometers and a Unit Field ID is provided:

- The Trigger Field value is treated as either Miles or Kilometers.
- If the unit field’s value contains "kilometer" or "km" (case-insensitive):
  - The numeric value is treated as Kilometers and converted to Miles:
    miles = kilometers × 0.621371
- Otherwise:
  - The numeric value is treated as Miles.

This is ideal for odometer conditions where users may enter either Miles
or Kilometers, but your rules are based on a Miles threshold (e.g. 250,000).

Evaluation Order
----------------
For each Gravity Form:

1. All affected choices for that form are reset to:
   - visible
   - enabled

2. Simple Rules are evaluated and applied.

3. Advanced Rules are evaluated and applied.
   - Any Advanced Rule can hide/disable a choice even if Simple Rules
     left it visible/enabled.

Server-Side Validation
----------------------
Simple Rules are enforced in Gravity Forms validation:

- If a disallowed choice is submitted, the target field fails with:
  "This option is not available with your selection."

Advanced Rules are currently front-end only (UX locking).

Version
-------
Stable Build: v2.8.0
- Human-readable Logic Mode labels
- Trigger terminology aligned between Simple and Advanced
- Miles/Kilometers numeric conversion via Unit Mode + Unit Field
- jQuery-free front-end script