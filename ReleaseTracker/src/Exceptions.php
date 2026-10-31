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
