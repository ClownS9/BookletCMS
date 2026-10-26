<?php

namespace App\Support;

use RuntimeException;

/** Why a passkey wasn't kept or let in, in words for the person (translated where it's shown). */
class PasskeyProblem extends RuntimeException {}
