# Registration Manager Reporting Module

**Source:** `Registration Manager Reporting Module.docx` (product owner source doc, external `docs/` folder). Extracted verbatim below for repo reference; see `docs/plans/event-version-orientation.md` for how this feeds into the build plan.

The Registration Manager must have access to update the registration status of schools, teachers, students, and candidates as well as run reports.

## Reports

All reports are based on the foundational criteria of matching `*.version_id` and under the school county assignment of the user.

### Obligated Teachers

- **Purpose:** Display the teachers and schools that have obligated to perform the duties outlined for the event.
- **Criteria:** All teachers who have confirmed values on `version_obligation_responses` table.
- **Properties:**
  - Teacher name (alpha)
  - Teacher phone numbers
  - Teacher's email address
  - Obligations accepted date
  - School name
  - Membership expiration date
- Searchable by first name, last name, school name
- Sortable by last name and school name
- Exportable to PDF

### Participating Teachers

- **Purpose:** Display the teachers who have candidates in status = "registered"
- **Criteria:** All teachers with matching `candidates.teacher_id` and `candidates.status = "registered"`.
- **Properties:**
  - Teacher name (alpha)
  - Teacher phone numbers
  - Teacher's email address
  - School name
  - Count of candidates with status = registered
- Searchable by first name, last name, school name
- Sortable by last name and school name
- Exportable to PDF

### Participating Schools

- **Purpose:** Registration and co-registration managers receive physical packets from teachers with materials required for each registered candidate. The registration/co-registration managers compare the information received from the teacher to the information in the system to ensure conformance and highlight differences. The first step is to confirm to the teacher that their packet has been received (packet-received checkbox below). When the box is checked an email should be sent to the teacher confirming receipt only. This mailing should be done as a batch rather than at each checkbox click and should use the teachers' `users.email` value. The second step is to register any payments included in the packet. A payment is registered by clicking a "Payment" button to open a modal form. Note: Electronic payments can be received from students and teachers. These payments should be recorded in separate tables. Payments from this modal form should be reported in the teacher's payment table.
- **Criteria:** All schools with matching `candidates.school_id` and `candidates.status = "registered"`.
- **Properties:**
  - School name
  - Teacher name (alpha)
  - Teacher phone numbers
  - Teacher's email address
  - Updateable checkbox to indicate that the teacher's packet has been received
  - Count of candidates with status = registered
  - Total amount of registration fees due
  - Total amount of registration fees paid
  - Balance due (due - paid). May be positive, negative (overpayment), or zero. Balance due should be a badge representing payment-due, over-payment, and no-balance-due states.
  - Button for registering a school/teacher's payment.
    - Payment form should be modal.
    - Payment form should include fields for:
      - Payment type (check, purchase order, cash, other)
      - Amount paid
      - Check or Transaction number
      - Comments
- Searchable by first name, last name, school name
- Sortable by last name and school name
- Filterable by packets outstanding (not received) and balance-due state
- Exportable to PDF

### Payment Roster

- **Purpose:** Provide the Registration Managers/Co-Registration Managers with a succinct summary of payments made for the event.
- **Criteria:** All payments made regardless of payment type (check, purchase order, cash, other, electronic).
- **Properties:**
  - School name
  - Teacher name (alpha)
  - Candidate name (alpha) if candidate electronic payment
  - Payment type (check, purchase order, cash, other, electronic)
  - Amount
  - Check or transaction number
  - Comments
- Searchable by school name, teacher name, candidate name
- Sortable by school name, teacher name, candidate name
- Filterable by school name, payment type
- Exportable to PDF, filter dependent

### Participating Candidates

- **Purpose:** Display all candidates and allow limited edits to candidate/student data
- **Criteria:** All candidates with `candidates.status = registered`
- **Properties:**
  - School name
  - Teacher name (alpha)
  - Teacher phone numbers
  - Teacher's email address
  - Candidate name (alpha)
  - Grade (class of)
  - Voice Part
  - Edit button
    - When clicked, modal form is displayed with the following fields:
      - Candidate name (first, middle, last)
      - Voice Part (with selection from event ensemble(s) voice parts)
      - Home phone
      - Cell phone
      - Emergency Contact selection from `emergency_contacts`
    - Remove button
      - Changes candidate status to either `withdrew` or `teacher_withdrawn`
- Searchable by school name, teacher name, candidate name
- Sortable by school name, teacher name, candidate name
- Filterable by school name, grade, voice part
- Exportable to PDF, filter dependent

### Participation by County

- **Purpose:** Display the count of data by county
- **Criteria:** All candidates with `candidate_status = registered`. Should include counties with no counts.
- **Properties:**
  - County name
  - Obligated teacher (count)
  - Participating teachers (count)
  - Candidates (count)
  - County registration manager name
- No search
- No sort
- No filters
- Exportable to PDF and CSV file

### Candidate Counts

- **Purpose:** Display of candidate counts by school name, teacher name, and voice part. Has a header of summary count of registered candidates by voice part (from event's ensemble(s) voice parts).
- **Criteria:** All candidates with `candidate_status = registered`.
- **Properties:**
  - School name
  - Teacher name (alpha)
  - Teacher phone numbers
  - Teacher's email address
  - Each voice part from event's ensembles (count)
  - Total (count of candidates from school; should equal count of candidates by voice part from school)
- Searchable by school name, teacher name
- Sortable by school name, teacher name, total
- No filters
- Exportable to PDF and CSV file

### Adjudication Backup

- **Purpose:** If, during an in-person audition, the internet was to go down, auditions should be capable of continuing using paper forms or via CSV files stored on the judges' laptops. Note: As there are no in-person auditions scheduled for the upcoming season, these should be populated with placeholders for the current time.
- **Criteria:** All candidates with `candidate.status = registered`
- **Properties:**
  - Room name
  - Paper (PDF copy of score sheet)
  - CSV (CSV copy of score sheet)
  - Checklist (PDF copy of monitor's room roster of candidates)

### Registration Cards

- **Purpose:** Provide Registration Manager(s) with the ability to print Registration Cards for an in-person audition. Note: As there are no in-person auditions scheduled for the upcoming season, these should be populated with placeholders for the current time.
- **Property:**
  - A single link to print PDF of registration cards
- **Filters:** Candidate id, school name, voice part (from event's ensemble(s) voice parts)
