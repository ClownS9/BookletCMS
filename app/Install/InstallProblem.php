<?php

namespace App\Install;

use RuntimeException;

/** Something the person installing can fix, said in words they can act on. */
final class InstallProblem extends RuntimeException {}
