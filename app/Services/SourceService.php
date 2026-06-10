<?php

namespace App\Services;

use App\Jobs\SourceJob;
use App\Models\Source;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class SourceService
{
    protected function supportsTags(): bool
    {
        return method_exists(Cache::getStore(), 'tags');
    }

    protected function clearCache(?string $sourceId = null): void
    {
        if ($this->supportsTags()) {
            Cache::tags(['sources'])->flush();
        } else {
            for ($i = 1; $i <= 10; $i++) {
                Cache::forget("source_page_{$i}");
            }
            if ($sourceId) {
                Cache::forget("source:{$sourceId}");
            }
        }
    }

    public function getAllSources(Request $request, int $limit = 10): LengthAwarePaginator
    {
        $page = $request->input('page', 1);
        $key = "source_page_{$page}";
        
        $retrieveData = function () use ($limit) {
            $sources = Source::query()
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->paginate();

            return [
                'data' => $sources->getCollection(),
                'total' => $sources->total(),
                'per_page' => $sources->perPage(),
                'current_page' => $sources->currentPage(),
            ];
        };

        if ($this->supportsTags()) {
            $data = Cache::tags(['sources', $key])->remember($key, 60 * 60 * 24, $retrieveData);
        } else {
            $data = Cache::remember($key, 60 * 60 * 24, $retrieveData);
        }

        return new LengthAwarePaginator(
            $data['data'],
            $data['total'],
            $data['per_page'],
            $data['current_page'],
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    public function createSource(array $data)
    {
        $source = Source::create($data);
        $this->clearCache();
        SourceJob::dispatchSync($source);

        return $source;
    }

    public function getSourceById(string $id)
    {
        return Source::find($id);
    }

    public function updateSource(Source $source, array $data)
    {
        $source->update($data);
        $this->clearCache($source->id);
        SourceJob::dispatchSync($source);

        return $source;
    }

    public function deleteSource(Source $source)
    {
        $sourceId = $source->id;
        $source->delete();
        $this->clearCache($sourceId);

        return true;
    }
}
