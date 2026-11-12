# StockForge: Project Task Tracker & Execution Roadmap

**Status Legend:**
- 🔴 `[TODO]` Pending
- 🟡 `[IN PROGRESS]` Currently being executed
- 🟢 `[DONE]` Completed and verified

---

## Phase 1: Architecture, ERD & System Blueprint
- [x] **Task 1.1:** Define project vision, requirements, and invariants document (`README.md`). 🟢
- [x] **Task 1.2:** Design complete Entity-Relationship Diagram (ERD) with relational constraints. 🟢
- [x] **Task 1.3:** Map Finite State Machines (FSM) for Reservations and Orders. 🟢
- [x] **Task 1.4:** Define RESTful API endpoints and Idempotency specifications. 🟢
- [x] **Task 1.5:** Review and finalize data modeling and audit all columns with user. 🟢

---

## Phase 2: Environment Setup & Infrastructure
- [x] **Task 2.1:** Create and verify `docker-compose.yml` (PostgreSQL 16 + Redis 7 + health checks). 🔴
- [x] **Task 2.2:** Initialize Laravel 12 API backend with PHP 8.4 and strict typing. 🔴
- [x] **Task 2.3:** Configure PostgreSQL and Redis connections inside Laravel `.env`. 🔴
- [x] **Task 2.4:** Build and test initial database migrations with all Foreign Keys & Check Constraints. 🔴
- [x] **Task 2.5:** Create API health check endpoint (`/api/v1/health`) verifying DB and Redis ping. 🔴

---

## Phase 3: The Concurrency Reservation Engine (Core)
- [ ] **Task 3.1:** Implement Double-Entry `StockMovement` ledger service. 🔴
- [ ] **Task 3.2:** Build Stock Inward/Adjustment API with ledger tracking. 🔴
- [ ] **Task 3.3:** Implement Concurrency Strategy A: Pessimistic Locking (`lockForUpdate` with sorted ID locks). 🔴
- [ ] **Task 3.4:** Implement Concurrency Strategy B: Atomic Conditional Update (`UPDATE ... WHERE available >= qty`). 🔴
- [ ] **Task 3.5:** Implement `IdempotencyMiddleware` supporting `Idempotency-Key` and request payload hashing. 🔴
- [ ] **Task 3.6:** Implement Reservation Expiry Queue Job (Redis delayed dispatch for 10-minute TTL). 🔴
- [ ] **Task 3.7:** Write Pest unit & integration tests for single-unit and multi-unit reservation claims. 🔴

---

## Phase 4: Order Lifecycle & Payment Webhooks
- [ ] **Task 4.1:** Implement Order creation service bound to active reservations. 🔴
- [ ] **Task 4.2:** Build Stripe integration service (PaymentIntent creation in test mode). 🔴
- [ ] **Task 4.3:** Build Stripe Webhook receiver with signature validation. 🔴
- [ ] **Task 4.4:** Implement Webhook deduplication using `payment_webhook_events` table. 🔴
- [ ] **Task 4.5:** Implement Expiry vs. Payment Race Condition guard (Atomic Compare-and-Swap). 🔴
- [ ] **Task 4.6:** Implement automated refund trigger if payment succeeds on an already expired reservation. 🔴

---

## Phase 5: Reconciliation & Observability
- [ ] **Task 5.1:** Build `inventory:reconcile` Artisan command to detect ledger vs counter discrepancies. 🔴
- [ ] **Task 5.2:** Add structured JSON logging with Request ID, Tenant ID, and Order ID tracing. 🔴
- [ ] **Task 5.3:** Create reconciliation inspection API endpoint (`/api/v1/reconciliation/report`). 🔴

---

## Phase 6: Operational Dashboard & Flash Sale Simulator (Next.js)
- [ ] **Task 6.1:** Bootstrap Next.js 15 (App Router) + TypeScript + Tailwind CSS project in `frontend/`. 🔴
- [ ] **Task 6.2:** Build live Inventory & Order status overview dashboard. 🔴
- [ ] **Task 6.3:** Build Flash Sale Simulator UI (virtual users, stock count, concurrency trigger). 🔴
- [ ] **Task 6.4:** Render real-time latency graphs, success/failure counters, and oversell alerts. 🔴

---

## Phase 7: Load Testing, Benchmarking & Portfolio Artifacts
- [ ] **Task 7.1:** Write k6 load test script simulating 500 concurrent users fighting for 20 units. 🔴
- [ ] **Task 7.2:** Benchmark Strategy A (Pessimistic) vs Strategy B (Atomic Update) under load. 🔴
- [ ] **Task 7.3:** Generate benchmark report (RPS, p95, p99 latency, 0-oversold verification). 🔴
- [ ] **Task 7.4:** Finalize GitHub README documentation and architecture walkthrough. 🔴
