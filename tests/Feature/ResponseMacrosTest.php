<?php

namespace RiseTechApps\RiseTools\Tests\Feature;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use RiseTechApps\RiseTools\Tests\TestCase;

class ResponseMacrosTest extends TestCase
{
    protected function tearDown(): void
    {
        // withoutWrapping() é estático: devolve o padrão para os próximos testes.
        JsonResource::wrap('data');

        parent::tearDown();
    }

    public function test_success_envelope(): void
    {
        $response = response()->jsonSuccess(['id' => 1]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'success' => true,
            'code' => 200,
            'message' => 'Operation completed successfully.',
            'data' => ['id' => 1],
        ], $response->getData(true));
    }

    public function test_status_codes_and_default_messages(): void
    {
        $cases = [
            'jsonError' => [422, 'Resource not available.'],
            'jsonGone' => [410, 'Resource no longer available.'],
            'jsonNotFound' => [404, 'Resource not found.'],
            'jsonInternal' => [500, 'Internal server error.'],
        ];

        foreach ($cases as $macro => [$code, $message]) {
            $data = response()->{$macro}()->getData(true);

            $this->assertFalse($data['success'], $macro);
            $this->assertSame($code, $data['code'], $macro);
            $this->assertSame($message, $data['message'], $macro);
        }
    }

    public function test_created_unauthorized_and_forbidden(): void
    {
        $created = response()->jsonCreated(['id' => 7]);
        $this->assertSame(201, $created->getStatusCode());
        $this->assertSame(['success' => true, 'code' => 201, 'message' => 'Resource created successfully.', 'data' => ['id' => 7]], $created->getData(true));

        $this->assertSame(['success' => false, 'code' => 401, 'message' => 'Unauthenticated.'], response()->jsonUnauthorized()->getData(true));
        $this->assertSame(['success' => false, 'code' => 403, 'message' => 'This action is unauthorized.'], response()->jsonForbidden()->getData(true));
    }

    public function test_no_content_has_no_body(): void
    {
        $response = response()->jsonNoContent(['X-Test' => '1']);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertSame('1', $response->headers->get('X-Test'));
    }

    public function test_validation_keeps_laravel_errors_format(): void
    {
        $errors = ['email' => ['The email field is required.']];

        foreach ([$errors, new \Illuminate\Support\MessageBag($errors)] as $input) {
            $response = response()->jsonValidation($input);

            $this->assertSame(422, $response->getStatusCode());
            $this->assertSame([
                'success' => false,
                'code' => 422,
                'message' => 'The given data was invalid.',
                'errors' => $errors,
            ], $response->getData(true));
        }
    }

    public function test_empty_array_is_kept_as_empty_list(): void
    {
        $this->assertSame([], response()->jsonSuccess([])->getData(true)['data']);
    }

    public function test_null_data_has_no_data_key(): void
    {
        $this->assertArrayNotHasKey('data', response()->jsonSuccess()->getData(true));
    }

    public function test_resource_with_additional_and_with(): void
    {
        $resource = ItemWithResource::make(['id' => 1])->additional(['permissions' => ['x']]);

        $data = response()->jsonSuccess($resource)->getData(true);

        $this->assertSame(['id' => 1], $data['data']);
        $this->assertSame(['x'], $data['permissions']);
        $this->assertSame('with()', $data['extra']);
        // with()/additional() não sobrescrevem o envelope.
        $this->assertTrue($data['success']);
    }

    public function test_paginated_collection_keeps_meta_and_links(): void
    {
        $paginator = new LengthAwarePaginator(collect([['id' => 3], ['id' => 4]]), 10, 2, 2, ['path' => '/api/items']);

        $data = response()->jsonSuccess(ItemResource::collection($paginator))->getData(true);

        $this->assertSame([['id' => 3], ['id' => 4]], $data['data']);
        $this->assertSame(10, $data['meta']['total']);
        $this->assertSame(2, $data['meta']['current_page']);
        $this->assertSame('/api/items?page=3', $data['links']['next']);
    }

    public function test_resource_without_wrapping(): void
    {
        ItemResource::withoutWrapping();

        $data = response()->jsonSuccess(ItemResource::make(['id' => 9]))->getData(true);

        $this->assertSame(['id' => 9], $data['data']);
    }
}

class ItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->resource['id']];
    }
}

class ItemWithResource extends ItemResource
{
    public function with($request): array
    {
        return ['extra' => 'with()', 'success' => 'must-not-override'];
    }
}
