# Documentation

## Product and design
| Document | Purpose |
|---|---|
| [Project scope](PROJECT_SCOPE.md) | What the system is for, in one page |
| [Requirements](REQUIREMENTS.md) | Confirmed scope, exclusions and defaults awaiting clinic approval |
| [Architecture](ARCHITECTURE.md) | Code structure, request flow, runtime processes and design rules |
| [Workflow](WORKFLOW.md) | The patient journey and the appointment, visit and ticket lifecycles |
| [Queue engine](QUEUE_ENGINE.md) | Numbering, call order, transitions, concurrency and announcements |
| [Database schema](DATABASE_SCHEMA.md) | Tables and the integrity rules enforced by PostgreSQL |
| [Roles and permissions](ROLES_AND_PERMISSIONS.md) | Roles, permissions, department scope and clinical access |
| [Security and privacy](SECURITY.md) | Security controls and privacy principles |

## Operations
| Document | Purpose |
|---|---|
| [Deployment](operations/DEPLOYMENT.md) | Infrastructure requirements and the manual release procedure |
| [Backup and restore](operations/BACKUP_AND_RESTORE.md) | Encrypted backups, the restore drill and the drill log |
| [Monitoring](operations/MONITORING.md) | Health probe, alerts and what to watch |
| [Runbooks](operations/RUNBOOKS.md) | Procedures for common incidents |
| [Threat model](operations/THREAT_MODEL.md) | Assets, threats, mitigations and residual risks |
| [Privacy review](operations/PRIVACY_REVIEW.md) | Data inventory and the decisions the clinic must make |

## Delivery
| Document | Purpose |
|---|---|
| [Implementation plan](IMPLEMENTATION_PLAN.md) | Phase overview and status |
| [Phase records](phases/) | Per-phase scope, design decisions and verification results |

## Conventions
- **Authority.** Where documents disagree, the code, its database constraints and its tests are authoritative. Phase records explain why a decision was made.
- **Proposed values.** Values marked *proposed* are working defaults that the clinic has not yet approved. Examples are grace periods and the role-to-permission mapping.
- **Data.** Examples use synthetic data only.
