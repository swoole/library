"""Minimal Helloworld.Greeter gRPC server for the Swoole\Grpc integration tests.

Speaks plain h2c on 0.0.0.0:50051, so the Swoole coroutine HTTP/2 client can connect without TLS.
"""

from concurrent import futures

import grpc
import helloworld_pb2
import helloworld_pb2_grpc


class Greeter(helloworld_pb2_grpc.GreeterServicer):
    # Any name starting with this marker requests a payload large enough to span several
    # HTTP/2 DATA frames, used to exercise the client's cross-frame reassembly.
    LARGE_MARKER = "LARGE:"
    LARGE_SIZE = 64 * 1024

    def SayHello(self, request, context):
        if request.name.startswith(self.LARGE_MARKER):
            body = self.LARGE_MARKER + ("x" * self.LARGE_SIZE)
            return helloworld_pb2.HelloReply(message=body)
        return helloworld_pb2.HelloReply(message="Hello " + request.name)


def serve():
    server = grpc.server(futures.ThreadPoolExecutor(max_workers=8))
    helloworld_pb2_grpc.add_GreeterServicer_to_server(Greeter(), server)
    server.add_insecure_port("0.0.0.0:50051")
    server.start()
    server.wait_for_termination()


if __name__ == "__main__":
    serve()
