<?php

namespace App\Http\Controllers;

use App\Http\Requests\Course\StoreCourseRequest;
use App\Http\Requests\Course\UpdateCourseRequest;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class CourseController extends Controller
{
    public function index(Request $request)
    {
        $query = Course::with(['teacher', 'category'])
            ->when($request->search, function ($q) use ($request) {
                $q->where('title', 'like', "%{$request->search}%")
                    ->orWhere('description', 'like', "%{$request->search}%");
            })
            ->when($request->category, function ($q) use ($request) {
                $q->where('category_id', $request->category);
            })
            ->when($request->has('is_premium'), function ($q) use ($request) {
                $q->where('is_premium', $request->boolean('is_premium'));
            })
            ->when($request->teacher, function ($q) use ($request) {
                $q->where('teacher_id', $request->teacher);
            });

        return CourseResource::collection($query->paginate(16));
    }

    public function store(StoreCourseRequest $request)
    {
        $validated = $request->validated();
        $validated['teacher_id'] = auth()->id();
        $validated['slug'] = Str::slug($validated['title']);

        if ($request->hasFile('thumbnail')) {
            $validated['thumbnail'] = $request->file('thumbnail')->store('courses', 'public');
        }

        $course = Course::create($validated);

        return new CourseResource($course->load(['teacher', 'category']));
    }

    public function show(Course $course, Request $request)
    {
        $course->load(['teacher', 'category', 'lessons']);

        $isEnrolled = false;
        $userType = 'guest';

        if ($request->user()) {
            $userType = 'authenticated';
            $isEnrolled = $course->enrollments()
                ->where('user_id', $request->user()->id)
                ->where('is_enrolled', true)
                ->exists();
        }

        \Log::info('Course detail access', [
            'user' => $request->user(),
            'is_authenticated' => auth()->check(),
            'user_type' => $userType,
            'is_enrolled' => $isEnrolled,
        ]);

        return (new CourseResource($course))->additional([
            'is_enrolled' => $isEnrolled,
            'user_type' => $userType
        ]);
    }

    public function update(UpdateCourseRequest $request, Course $course)
    {
        \Log::info('Request data:', [
            'name' => $request->input('name'),
            'has_file' => $request->hasFile('thumbnail'),
            'all_data' => $request->all()
        ]);

        $validated = $request->validated();

        if ($request->hasFile('thumbnail')) {
            if ($course->thumbnail) {
                Storage::disk('public')->delete($course->thumbnail);
            }
            $validated['thumbnail'] = $request->file('thumbnail')->store('courses', 'public');
        }

        if (isset($validated['title'])) {
            $validated['slug'] = Str::slug($validated['title']);
        }

        $course->update($validated);

        return new CourseResource($course->load(['teacher', 'category']));
    }

    public function destroy(Course $course)
    {
        $this->authorize('delete', $course);

        if ($course->thumbnail) {
            Storage::disk('public')->delete($course->thumbnail);
        }

        $course->delete();

        return response()->json(['message' => 'Course deleted successfully']);
    }
}
