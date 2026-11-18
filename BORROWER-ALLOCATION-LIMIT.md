# Final three-outstanding-book-slot policy

Each registered borrower has three outstanding slots. Ordinary Pending, Awaiting Pickup, Checked Out and legitimate active legacy Approved records count, as do Ready and Fulfilled reservations. Waiting reservations, Rejected, Cancelled and Returned records do not count. Each transaction counts once; approval and checkout are transitions, not additional slots.

New student requests, manual checkouts, Ready allocations and direct Waiting checkout require an available outstanding slot. They share the existing book-then-borrower lock order and count before the first consistent read. Existing pending approval and allocated checkout do not reserve another slot. Inventory holds and physical stock checks remain independent.

Every checkout also enforces three actual physical loans (ordinary Checked Out/legacy Approved plus reservation Fulfilled). Existing over-limit records are preserved: no extra request or allocation is permitted, but a valid existing pickup hold can convert to a loan if fewer than three physical loans are active. It cannot create a fourth physical loan. Returns, rejection and existing permitted cancellation release their corresponding slots. No new cancellation privilege is added.

The catalog displays **Available Book Slots: X/3** from backend data and disables new borrowing requests at zero. Waiting-list signup remains available without allocation. Successful actions and data refreshes reload the count. QR and desktop final checkout warnings use the separate physical-loan count, so zero outstanding slots does not incorrectly block an already allocated third checkout. All final decisions are transactionally revalidated by the backend.

Run `php tests/post-defense-combined-limit.php --run /path/to/local/checkout` for isolated synthetic workflow and real overlapping-transaction checks. The supplied path provides local connection settings only; its database is never selected. The runner creates/removes only its own schema and disables provider HTTP. No migration, scheduling or production activation is included.
