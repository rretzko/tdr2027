# Web Registration Manager Module

**Source:** `Web Registration Manager Module.docx` (product owner source doc, external `docs/` folder). Extracted verbatim below for repo reference; see `docs/plans/event-version-orientation.md` §5.11 for how this feeds into the build plan. The source doc also embeds one screenshot of the legacy UI, described after the text.

The Online Registration Manager role is able to perform two online functions:

- Impersonate another teacher
  - Limited by version_id access
  - Limited to teachers who have been invited
  - Online Registration Manager should NOT have access to Teacher profile
- Perform student transfers from one teacher to another
  - Limited by version_id access
  - Limited to teachers who have been invited
  - Limited to students in the current Senior year or greater (i.e. current students)
  - Used to move students from one teacher to another at the same or different schools when:
    - One teacher replaces another within a school
    - Student transfers from one school to another
    - Student is promoted from one school to another (ex. Middle to high school).
  - An example of the current functionality:
    - When transferred, the system should update candidate records for transferred students at the winning school and update the teacher_id for those candidates.
    - When transferred, the system should add candidate records for any open event versions for which the teacher is invited and the student is eligible.

## Legacy screenshot ("An example of the current functionality")

A two-panel "Transfer From" / "Transfer To" layout:

- **Transfer From** (left, pink header): a School dropdown, a Teacher dropdown, and a checkbox list headed "Students (N)" — each row is a student name plus their `class_of` year in parentheses.
- **Transfer To** (right, green header): a School dropdown, a Teacher dropdown, and a read-only list headed "Current Students (N)" — the destination teacher's existing current roster, for context only, no checkboxes.
- A single "Transfer N Students" button below both panels, its count reflecting how many checkboxes are currently checked on the From side.
