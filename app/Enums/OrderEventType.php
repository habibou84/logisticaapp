<?php

namespace App\Enums;

enum OrderEventType: string
{
    case Created = 'created';
    case Edited = 'edited';
    case StatusChanged = 'status_changed';
    case Assigned = 'assigned';
    case AssignmentAccepted = 'assignment_accepted';
    case AssignmentRefused = 'assignment_refused';
    case Unassigned = 'unassigned';
    case Note = 'note';
    case Incident = 'incident';
    case ReturnRequested = 'return_requested';
    case ProofAdded = 'proof_added';
    case ExpenseAdded = 'expense_added';
    case ExpenseCancelled = 'expense_cancelled';
    case ParcelReceived = 'parcel_received';
}
