<?php
class Response {

    public static function success($data = [], string $message = 'Success', int $code = 200): void {
        http_response_code($code);
        echo json_encode(['success' => true, 'message' => $message, 'data' => $data]);
        exit;
    }

    public static function error(string $message = 'Error', int $code = 400, array $errors = []): void {
        http_response_code($code);
        $payload = ['success' => false, 'message' => $message];
        if (!empty($errors)) $payload['errors'] = $errors;
        echo json_encode($payload);
        exit;
    }

    public static function paginated(array $items, int $total, int $page, int $perPage): void {
        echo json_encode([
            'success' => true,
            'data'    => $items,
            'meta'    => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int)ceil($total / $perPage),
            ],
        ]);
        exit;
    }
}
