# HelpDesk — Project Plan

## 1. Product Overview

HelpDesk is a multi-tenant SaaS ticket management platform designed
for technical support organizations to track, manage, and resolve
client issues.

The platform is intended for support teams ranging from individual
technicians to management.

## 2. Target Users

### Support Technicians

Technicians are responsible for working on and resolving client
tickets.

Technicians can:
- View tickets
- Create tickets
- Edit tickets
- Assign unassigned tickets to themselves
- Add comments
- Update ticket status
- Resolve tickets

Technicians cannot:
- Assign tickets to other technicians
- Delete tickets
- Manage users
- View reports by default
- Modify organization settings

### Managers

Managers oversee the support operation and technician workload.

Managers can:
- View all tickets
- Create and edit tickets
- Assign tickets to technicians
- Delete tickets
- Manage clients
- View reports
- Resolve and close tickets

### Administrators

Administrators have full control over the organization.

Administrators can:
- Perform all manager functions
- Manage users
- Manage organization settings
- Manage system configuration

### Client Contacts

Client contacts represent individuals belonging to customer
organizations.

Client contacts will not have application access in V1. A future
version may introduce a client-facing portal.

## 3. Ticket Rules

### Assignment

Technicians may assign an unassigned ticket to themselves.

Managers and administrators may assign tickets to any technician.

Technicians may not assign tickets to another technician.

### Resolution and Closure

A ticket must have an assigned technician before it can be
resolved or closed.

The application must enforce this rule server-side.

### Deletion

Managers and administrators may delete tickets.

Ticket deletion should initially be implemented as a soft-delete
so that historical and audit information can be preserved.

## 4. Future Authorization

V1 will use three predefined roles:

- Administrator
- Manager
- Technician

The authorization system should be designed around individual
permissions so that customizable roles and permissions can be
introduced in a future version.
