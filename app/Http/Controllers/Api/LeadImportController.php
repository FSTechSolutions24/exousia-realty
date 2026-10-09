<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadImport;
use App\Models\PipelineStage;
use App\Models\PreferredLocation;
use App\Services\AuditLogger;
use App\Services\EgyptianPhoneNormalizer;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LeadImportController extends Controller
{
    private const HEADERS = ['name', 'phone', 'email', 'source', 'intent', 'budget_min', 'budget_max', 'preferred_locations', 'property_type', 'bedrooms', 'next_follow_up_at', 'notes'];

    public function template(Request $request)
    {
        $this->authorizeImport($request);
        return response()->streamDownload(function () {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, self::HEADERS);
            fclose($output);
        }, 'lead-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function preview(Request $request, TenantContext $tenant, EgyptianPhoneNormalizer $phones)
    {
        $this->authorizeImport($request);
        $file = $this->validateFile($request);
        $rows = $this->readRows($file->getRealPath());
        $preview = $this->validateRows($rows, $tenant, $phones);
        $existingImport = LeadImport::where('company_id', $tenant->id())->where('file_hash', hash_file('sha256', $file->getRealPath()))->first();

        return response()->json([
            'already_imported' => (bool) $existingImport,
            'previous_result' => $existingImport ? $existingImport->only(['row_count', 'created_count', 'skipped_count']) : null,
            'total_rows' => count($preview),
            'valid_rows' => collect($preview)->where('valid', true)->count(),
            'invalid_rows' => collect($preview)->where('valid', false)->count(),
            'rows' => $preview,
        ]);
    }

    public function import(Request $request, TenantContext $tenant, EgyptianPhoneNormalizer $phones, AuditLogger $audit)
    {
        $this->authorizeImport($request);
        $file = $this->validateFile($request);
        $rows = $this->readRows($file->getRealPath());
        $preview = $this->validateRows($rows, $tenant, $phones);
        $invalid = collect($preview)->where('valid', false);
        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages($invalid->mapWithKeys(fn ($row) => ['row_'.$row['row_number'] => implode(' ', $row['errors'])])->all());
        }

        $fileHash = hash_file('sha256', $file->getRealPath());
        $result = DB::transaction(function () use ($tenant, $request, $preview, $fileHash, $audit) {
            Company::whereKey($tenant->id())->lockForUpdate()->firstOrFail();
            $existing = LeadImport::where('company_id', $tenant->id())->where('file_hash', $fileHash)->first();
            if ($existing) return ['already_imported' => true, 'batch' => $existing];

            $stageId = PipelineStage::where('company_id', $tenant->id())->orderBy('position')->value('id');
            abort_if(! $stageId, 422, 'Create a pipeline stage before importing leads.');
            $membership = $request->attributes->get('membership');
            $batch = LeadImport::create([
                'company_id' => $tenant->id(), 'imported_by' => $request->user()->id,
                'file_hash' => $fileHash, 'row_count' => count($preview), 'created_count' => 0, 'skipped_count' => 0,
            ]);
            foreach ($preview as $row) {
                $data = $row['data'];
                $lead = Lead::create([
                    'company_id' => $tenant->id(), 'pipeline_stage_id' => $stageId,
                    'assigned_to' => $membership->role === 'agent' ? $request->user()->id : null,
                    'created_by' => $request->user()->id, 'name' => $data['name'],
                    'phone_original' => $data['phone'], 'phone_normalized' => $data['phone_normalized'],
                    'email' => $data['email'], 'source' => $data['source'], 'intent' => $data['intent'],
                    'budget_min' => $data['budget_min'], 'budget_max' => $data['budget_max'],
                    'preferred_locations' => $data['preferred_locations'], 'property_type' => $data['property_type'],
                    'bedrooms' => $data['bedrooms'], 'notes' => $data['notes'], 'next_follow_up_at' => $data['next_follow_up_at'],
                ]);
                LeadActivity::create([
                    'company_id' => $tenant->id(), 'lead_id' => $lead->id, 'user_id' => $request->user()->id,
                    'type' => 'created', 'title' => 'Lead imported', 'occurred_at' => now(),
                    'metadata' => ['import_id' => $batch->id, 'source_row' => $row['row_number']],
                ]);
                $audit->log($request, 'lead.imported', $lead, [], ['import_id' => $batch->id, 'source_row' => $row['row_number']]);
            }
            $batch->update(['created_count' => count($preview)]);
            return ['already_imported' => false, 'batch' => $batch->fresh()];
        });

        return response()->json([
            'already_imported' => $result['already_imported'],
            'total_rows' => $result['batch']->row_count,
            'created_count' => $result['batch']->created_count,
            'skipped_count' => $result['batch']->skipped_count,
        ], $result['already_imported'] ? 200 : 201);
    }

    private function validateFile(Request $request)
    {
        return $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']])['file'];
    }

    private function readRows(string $path): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) throw ValidationException::withMessages(['file' => 'The CSV file could not be read.']);
        $headers = fgetcsv($handle);
        if (! is_array($headers)) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'The CSV file is empty.']);
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        $headers = array_map(fn ($header) => strtolower(trim((string) $header)), $headers);
        if (array_diff(['name', 'phone'], $headers) || count($headers) !== count(array_unique($headers))) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'The CSV needs unique column names and must include name and phone headers.']);
        }
        $rows = [];
        $line = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) continue;
            if (count($rows) >= 500) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => 'A file can contain up to 500 lead rows.']);
            }
            if (count($values) > count($headers)) {
                $rows[] = ['row_number' => $line, 'overflow' => true, 'values' => []];
                continue;
            }
            $values = array_pad($values, count($headers), '');
            $rows[] = ['row_number' => $line, 'overflow' => false, 'values' => array_combine($headers, array_map(fn ($value) => trim((string) $value), $values))];
        }
        fclose($handle);
        if (! $rows) throw ValidationException::withMessages(['file' => 'The CSV contains no lead rows.']);
        return $rows;
    }

    private function validateRows(array $rows, TenantContext $tenant, EgyptianPhoneNormalizer $phones): array
    {
        $seenPhones = [];
        $validatedRows = array_map(function (array $row) use ($tenant, $phones, &$seenPhones) {
            if ($row['overflow']) return ['row_number' => $row['row_number'], 'valid' => false, 'errors' => ['This row has more values than the header columns.'], 'data' => []];
            $values = $row['values'];
            $locations = array_values(array_filter(array_map('trim', explode('|', (string) ($values['preferred_locations'] ?? ''))), fn ($value) => $value !== ''));
            $data = [
                'name' => $values['name'] ?? null, 'phone' => $values['phone'] ?? null,
                'email' => $values['email'] ?? null, 'source' => ($values['source'] ?? '') ?: 'manual',
                'intent' => ($values['intent'] ?? '') ?: 'buy',
                'budget_min' => ($values['budget_min'] ?? '') === '' ? null : $values['budget_min'],
                'budget_max' => ($values['budget_max'] ?? '') === '' ? null : $values['budget_max'],
                'preferred_locations' => $locations ?: null,
                'property_type' => ($values['property_type'] ?? '') ?: null,
                'bedrooms' => ($values['bedrooms'] ?? '') === '' ? null : $values['bedrooms'],
                'notes' => ($values['notes'] ?? '') ?: null,
                'next_follow_up_at' => ($values['next_follow_up_at'] ?? '') ?: null,
            ];
            $validator = Validator::make($data, [
                'name' => ['required', 'string', 'max:160'], 'phone' => ['required', 'string', 'max:30'],
                'email' => ['nullable', 'email', 'max:190'], 'source' => ['nullable', 'string', 'max:50'],
                'intent' => ['nullable', 'in:buy,rent,sell'], 'budget_min' => ['nullable', 'integer', 'min:0'],
                'budget_max' => ['nullable', 'integer', 'min:0'], 'preferred_locations' => ['nullable', 'array', 'max:10'],
                'preferred_locations.*' => ['string', 'max:100'], 'property_type' => ['nullable', 'string', 'max:40'],
                'bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'], 'notes' => ['nullable', 'string', 'max:10000'],
                'next_follow_up_at' => ['nullable', 'date'],
            ]);
            $errors = $validator->errors()->all();
            if (! $validator->fails() && isset($data['budget_min'], $data['budget_max']) && $data['budget_max'] < $data['budget_min']) {
                $errors[] = 'Maximum budget must be at least the minimum budget.';
            }
            if ($locations) {
                $available = PreferredLocation::where('company_id', $tenant->id())->where('is_active', true)
                    ->whereIn('name', $locations)->pluck('name')->mapWithKeys(fn ($name) => [mb_strtolower($name) => $name]);
                $missing = array_filter($locations, fn ($name) => ! $available->has(mb_strtolower($name)));
                if ($missing) $errors[] = 'Unknown or inactive workspace location: '.implode(', ', $missing).'.';
                else $data['preferred_locations'] = array_map(fn ($name) => $available[mb_strtolower($name)], $locations);
            }
            $normalized = null;
            if (! $validator->errors()->has('phone')) {
                try { $normalized = $phones->normalize((string) $data['phone']); }
                catch (\InvalidArgumentException $exception) { $errors[] = $exception->getMessage(); }
            }
            $duplicate = null;
            if ($normalized) {
                if (isset($seenPhones[$normalized])) $duplicate = ['name' => $seenPhones[$normalized], 'existing' => false];
                $seenPhones[$normalized] = $data['name'] ?: 'another imported row';
            }
            $data['phone_normalized'] = $normalized;
            return [
                'row_number' => $row['row_number'], 'valid' => count($errors) === 0,
                'errors' => $errors, 'duplicate_warning' => $duplicate,
                'data' => array_intersect_key($data, array_flip(['name', 'phone', 'email', 'source', 'intent', 'budget_min', 'budget_max', 'preferred_locations', 'property_type', 'bedrooms', 'notes', 'next_follow_up_at', 'phone_normalized'])),
            ];
        }, $rows);

        $normalizedPhones = collect($validatedRows)->pluck('data.phone_normalized')->filter()->unique()->values();
        if ($normalizedPhones->isNotEmpty()) {
            $existingLeads = Lead::where('company_id', $tenant->id())->whereIn('phone_normalized', $normalizedPhones)
                ->get(['phone_normalized', 'name'])->keyBy('phone_normalized');
            foreach ($validatedRows as &$row) {
                $existing = $existingLeads->get($row['data']['phone_normalized'] ?? '');
                if ($existing) $row['duplicate_warning'] = ['name' => $existing->name, 'existing' => true];
            }
            unset($row);
        }
        return $validatedRows;
    }

    private function authorizeImport(Request $request): void
    {
        abort_unless($request->attributes->get('membership')->can('create_leads'), 403);
    }
}
