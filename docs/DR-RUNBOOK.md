# DR Runbook — Hotel PMS

> Tested steps, owners, escalation. The quarterly `dr:smoke` drill keeps
> this honest: `dr_drills` records every run with RTO/RPO, and CI fails
> when the latest passed drill is older than 120 days.

## Targets

- RTO 60 min (single property), 4 h (full region).
- RPO 24 h (nightly base + WAL to R2 cross-region).

## Backup layout (Spatie, already scheduled)

- `backup:run --only-db` daily 03:30 → R2.
- `backup:run` full weekly Sunday 04:00 → R2.
- `backup:clean` daily 03:00.
- Monitor: MaximumAgeInDays 1 + MaximumStorage 5 GB on R2 (fires
  `UnHealthyBackupWasFound`; wire it to `BackupFailed` broadcast).

## Restore drill (quarterly, owner: Global Admin)

1. `php artisan dr:smoke --dry-run` — backup presence + primary reads.
2. `php artisan dr:smoke` — scratch-restores the newest zip, asserts a
   `.sql`/`.dump` payload exists.
3. Full restore to staging (region loss): provision staging Postgres,
   `pg_restore` the newest base, replay WAL to the RPO point, run the
   smoke suite, record RTO/RPO in `dr_drills` (`mode=restore`).
4. Queues: pause Horizon (`horizon:pause`), drain `night-audit` first on
   restart — audit windows must not double-post after a restore.

## Replica

- Reads: `ReadFromReplica` middleware on GET analytics/report/warehouse
  routes; services read via `ReadRouter::connection()`. Writes always
  hit the primary; read-your-write relies on Postgres async apply
  (report staleness is surfaced, never silent).
- Lag > 60 s: `ReplicaLagHigh` fires and responses carry
  `X-Replica-Stale: true`. Never block OLTP for the replica.
- Failover: promote replica, point `DB_HOST` at it, restart Horizon +
  Reverb, run `dr:smoke --dry-run`.

## Partitioning

- `activity_log` is monthly RANGE-partitioned on `created_at`;
  `PartitionManagerJob` (monthly) pre-provisions 3 months. Retention
  deletes work across partitions unchanged.
- `transactions` converts via `db:partition-transactions` in a DBA
  window (traffic drained, pre-migrate backup taken): monthly RANGE
  on `business_date`, composite PK `(business_date, id)`, composite
  FKs from `pos_charges`/`folio_disputes`/`transaction_splits`
  (populated at link time), self-FK dropped to app-level
  (`transferToMaster()` already guards idempotency). `--dry-run`
  reports first; `--rollback` restores the plain table. `journal_entries`
  stays unpartitioned (no inbound FKs yet — same treatment when it
  earns it). Safe under ~10M rows; watch vacuum/lock pain as the
  trigger.

## Escalation

1. On-call engineer → 2. Property GM → 3. Platform owner.
   Region loss: declare within 30 min, staging restore within 4 h.
