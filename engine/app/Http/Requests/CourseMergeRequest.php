<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Course;

/**
 * Führt einen Umlauf in einen anderen über — der Weg für zwei Umläufe, die sich als derselbe
 * herausstellen (dieselbe Nummer auf einer gemeinsamen Linie).
 */
final class CourseMergeRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'source_id' => ['required', 'integer', 'exists:courses,id'],
        ];
    }

    public function source(): Course
    {
        return Course::query()->findOrFail($this->integer('source_id'));
    }
}
