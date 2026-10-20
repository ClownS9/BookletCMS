<?php

namespace App\Booklet\Repositories;

use App\Booklet\Data\Submission;
use Illuminate\Support\Collection;

/**
 * @extends Repository<Submission>
 */
class SubmissionRepository extends Repository
{
    /**
     * @return Collection<int, Submission>
     */
    public function whereForm(string $form): Collection
    {
        return $this->where(['form' => $form])->sortByDesc(fn (Submission $s) => $s->id())->values();
    }
}
