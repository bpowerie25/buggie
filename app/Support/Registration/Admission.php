<?php

namespace App\Support\Registration;

/**
 * Why somebody was let in to register. Recorded because the reasons are not equal:
 * only the first account on an install becomes an operator.
 */
enum Admission
{
    /** Nobody has an account yet. Exactly one registration gets this. */
    case FirstRun;

    /** The install is open to anybody. */
    case Open;

    /** They are carrying a live workspace invitation. */
    case Invitation;
}
