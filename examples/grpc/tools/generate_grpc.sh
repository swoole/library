#!/usr/bin/env bash
__CURRENT__=$(pwd)
__DIR__=$(cd "$(dirname "$0")" && pwd)

cd "${__DIR__}" &&
./generator \
--proto_path=./../proto \
--php_out=./../generated/Grpc \
--grpc_out=./../generated/Grpc \
--plugin=protoc-gen-grpc="${__CURRENT__}/$1" \
./../proto/helloworld.proto
cd "${__CURRENT__}"
