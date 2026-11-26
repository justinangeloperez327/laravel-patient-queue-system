# Laravel Patient Queue System

A Laravel-based patient queue management system for clinics and outpatient facilities.

## Project status
Planning and architecture discussion. Application features and technical choices are not yet finalized.

## Proposed scope
- Patient registration and queue ticket issuance
- Department- and service-specific queues
- Staff roles and access controls
- Queue calling, recall, skip, transfer, and completion
- Real-time waiting area display
- Queue history and operational reporting

## Proposed lifecycle
Registered → Waiting → Called → Serving → Completed

Additional outcomes: Skipped, Cancelled, Transferred. These require defined rules for rejoining and audit history.

## Decisions before implementation
1. Single clinic or multi-branch support?
2. Walk-in only or appointments as well?
3. Laravel monolith (Blade/Livewire) or separate frontend/API?
4. Department/service routing and queue numbering rules?
5. Patient data minimization, privacy, and retention requirements?

## Getting started
This repository currently contains project-planning files only. The Laravel application will be scaffolded once the initial architecture decisions are agreed. No deployment is configured.

## License
Not yet selected.
