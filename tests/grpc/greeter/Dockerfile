FROM python:3.12-slim

# Pin the pair so the generated _pb2_grpc.py always matches the runtime.
RUN pip install --no-cache-dir grpcio==1.68.1 grpcio-tools==1.68.1

WORKDIR /app
COPY helloworld.proto ./
RUN python -m grpc_tools.protoc -I. --python_out=. --grpc_python_out=. helloworld.proto
COPY server.py ./

EXPOSE 50051
CMD ["python", "server.py"]
