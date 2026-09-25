FROM golang:1.24-alpine AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY *.go ./
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /valkey-gateway .

FROM gcr.io/distroless/static:nonroot
COPY --from=build /valkey-gateway /valkey-gateway
USER nonroot
ENTRYPOINT ["/valkey-gateway"]
