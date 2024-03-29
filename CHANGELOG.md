# Change Log

## NOT RELEASED

## 1.0.2

### Changed

- Improve parameter type and return type in phpdoc

## 1.0.1

### Fixed

- Removed `urlencode()` and added bucket to `CopySource` in the `copy()` method. This comply with the Flysystem interface and the AWS adapter.

## 1.0.0

### Removed

- Support for Flysystem 2 as it is released under the Flysystem namespace.

### Changed

- Renamed `S3FilesystemV1` to `AsyncAwsS3Adapter`

## 0.4.2

### Added

- Use `SimpleS3Client`, when available, to upload big files.

## 0.4.1

### Added

- Support for PHP 8

## 0.4.0

### Added

- Support for `pathStyleEndpoint` option
- Added `S3FilesystemV1::getBucket()` and `S3FilesystemV1::getClient()` to expose private properties.

## 0.3.3

### Changed

- Support only version 1.0 of async-aws/core

## 0.3.2

### Fixed

- Better check if file exists.

## 0.3.1

### Added

- Support for Flysystem v2 alpha 1
- Support for async-aws/s3:0.4

## 0.3.0

### Added

- Support for Flysystem v1
- Support for Flysystem v2 alpha 1

## 0.2.0

Updated MimeType detection.

## 0.1.0

First version
