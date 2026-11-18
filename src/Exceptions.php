<?php

declare(strict_types=1);

class AppException extends RuntimeException
{
}

class ValidationException extends AppException
{
}

class ConflictException extends AppException
{
}

/**
 * Xung đột khóa lạc quan khi cập nhật công việc. Mang theo bản hiện tại
 * trong CSDL để giao diện hiển thị so sánh hai bản cạnh nhau.
 */
class VersionConflictException extends ConflictException
{
    public function __construct(string $message, public readonly array $current = [])
    {
        parent::__construct($message);
    }
}

/**
 * Từ chối thêm phụ thuộc vì tạo vòng. $cycle là chuỗi id (đỉnh đầu lặp lại
 * ở cuối), $titles là [id => tiêu đề] để giao diện tô đậm từng mắt xích.
 */
class CycleException extends ConflictException
{
    public function __construct(
        string $message,
        public readonly array $cycle = [],
        public readonly array $titles = []
    ) {
        parent::__construct($message);
    }
}

class NotFoundException extends AppException
{
}

class ForbiddenException extends AppException
{
}

function userMessage(Throwable $exception): string
{
    if ($exception instanceof AppException) {
        return $exception->getMessage();
    }
    $known = [
        'DATABASE_CONFIG_INVALID' => 'Cấu hình cơ sở dữ liệu không hợp lệ.',
        'DATABASE_CONFIG_MISSING' => 'Chưa cấu hình kết nối cơ sở dữ liệu.',
        'DATABASE_UNAVAILABLE' => 'Không kết nối được cơ sở dữ liệu.',
        'DATABASE_OPERATION_FAILED' => 'Thao tác cơ sở dữ liệu thất bại.',
        'DATABASE_TRANSACTION_STATE_INVALID' => 'Trạng thái transaction không hợp lệ.',
        'PGSQL_EXTENSION_MISSING' => 'Thiếu phần mở rộng pgsql của PHP.',
        'DATABASE_SCHEMA_INVALID' => 'Tên schema cơ sở dữ liệu không hợp lệ.',
        'DATABASE_SCHEMA_UNAVAILABLE' => 'Không sử dụng được schema cơ sở dữ liệu.',
    ];
    $code = $exception->getMessage();
    if (isset($known[$code])) {
        return $known[$code];
    }
    error_log('UNEXPECTED_ERROR: ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
    return 'Đã xảy ra lỗi không mong muốn. Vui lòng thử lại.';
}
