<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Api_category extends Controller {

    public function __construct() {
        parent::__construct();
        $this->call->model('Category_model');
        $currentUser = require_api_auth();

        if (strtolower((string) ($currentUser['role_name'] ?? '')) !== 'admin') {
            api_error('Forbidden: Administrator access required.', 403);
        }
    }

    public function index() {
        try {
            $categories = $this->Category_model->get_all();
            api_response($categories);
        } catch (\Exception $e) {
            api_error('Could not fetch categories: ' . $e->getMessage(), 500);
        }
    }

    public function show($id) {
        if (!ctype_digit((string) $id) || (int) $id < 1) {
            api_error('Invalid category ID.', 400);
        }

        $category = $this->Category_model->get_by_id($id);
        if (!$category) {
            api_error('Category not found.', 404);
        }

        api_response($category);
    }

    public function store() {
        $input = get_request_input();
        api_validate_input(is_array($input) ? $input : [], [
            'category_name' => 'required',
        ]);
        $categoryName = isset($input['category_name']) && is_string($input['category_name'])
            ? trim($input['category_name'])
            : '';
        $description = isset($input['description']) && is_string($input['description'])
            ? trim($input['description'])
            : '';

        if (preg_match('/^.{1,100}$/us', $categoryName) !== 1) {
            api_error('Category name is required and must be 100 characters or fewer.', 422);
        }

        $data = [
            'category_name' => $categoryName,
            'description'   => $description === '' ? null : $description,
            'is_active'     => isset($input['is_active']) && ($input['is_active'] === '1' || $input['is_active'] === 1 || $input['is_active'] === true) ? 1 : 0
        ];

        try {
            $id = $this->Category_model->insert($data);
            api_response(['category_id' => $id], 201, 'Category added successfully.');
        } catch (\Exception $e) {
            api_error('Failed to add category: ' . $e->getMessage(), 400);
        }
    }

    public function update($id) {
        if (!ctype_digit((string) $id) || (int) $id < 1) {
            api_error('Invalid category ID.', 400);
        }

        $category = $this->Category_model->get_by_id($id);
        if (!$category) {
            api_error('Category not found.', 404);
        }

        $input = get_request_input();
        api_validate_input(is_array($input) ? $input : [], [
            'category_name' => 'required',
        ]);
        $categoryName = isset($input['category_name']) && is_string($input['category_name'])
            ? trim($input['category_name'])
            : '';
        $description = isset($input['description']) && is_string($input['description'])
            ? trim($input['description'])
            : '';

        if (preg_match('/^.{1,100}$/us', $categoryName) !== 1) {
            api_error('Category name is required and must be 100 characters or fewer.', 422);
        }

        $data = [
            'category_name' => $categoryName,
            'description'   => $description === '' ? null : $description,
            'is_active'     => isset($input['is_active']) && ($input['is_active'] === '1' || $input['is_active'] === 1 || $input['is_active'] === true) ? 1 : 0
        ];

        try {
            $this->Category_model->update($id, $data);
            api_response(null, 200, 'Category updated successfully.');
        } catch (\Exception $e) {
            api_error('Failed to update category: ' . $e->getMessage(), 400);
        }
    }

    public function delete($id) {
        if (!ctype_digit((string) $id) || (int) $id < 1) {
            api_error('Invalid category ID.', 400);
        }

        $category = $this->Category_model->get_by_id($id);
        if (!$category) {
            api_error('Category not found.', 404);
        }

        try {
            $this->Category_model->delete($id);
            api_response(null, 200, 'Category deleted successfully.');
        } catch (\Exception $e) {
            api_error('Failed to delete category: ' . $e->getMessage(), 400);
        }
    }
}
