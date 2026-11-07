# CoreAI 0.6.37 (P20.5 A): stable-diffusion.cpp for the image test (scripts/image-spike.sh).
# Built on the server itself: ggml's native build uses exactly that CPU's instructions
# (AVX-512 where it has them). The source is the release tag in tools/image_spike/models.json,
# checked out by its full commit; only the ggml submodule is fetched (CPU build, no CUDA,
# no WebP/WebM). The weights are not in the image: the test loads them into
# var/image-spike/models and registers them with revision and SHA-256.
FROM debian:bookworm-slim AS build
ARG SD_REPO=https://github.com/leejet/stable-diffusion.cpp
ARG SD_COMMIT=d30854463dbc75fb3671209b79016bb0d8ac321e
ARG GGML_COMMIT=ccb88f810be05bccc9621e706354611ccc2462c7
RUN apt-get update \
 && apt-get install -y --no-install-recommends build-essential cmake git ca-certificates \
 && rm -rf /var/lib/apt/lists/*
RUN git clone --filter=blob:none "$SD_REPO" /src \
 && cd /src \
 && git checkout --detach "$SD_COMMIT" \
 && git submodule update --init --filter=blob:none ggml \
 && test "$(git -C ggml rev-parse HEAD)" = "$GGML_COMMIT"
RUN cmake -S /src -B /build -DCMAKE_BUILD_TYPE=Release -DSD_WEBP=OFF -DSD_WEBM=OFF -DGGML_NATIVE=ON \
 && cmake --build /build --target sd-cli -j"$(nproc)" \
 && /build/bin/sd-cli --help > /dev/null

FROM debian:bookworm-slim
RUN apt-get update \
 && apt-get install -y --no-install-recommends python3 ca-certificates libgomp1 libstdc++6 \
 && rm -rf /var/lib/apt/lists/*
COPY --from=build /build/bin/sd-cli /usr/local/bin/sd-cli
COPY --from=build /src/LICENSE /usr/share/doc/stable-diffusion.cpp/LICENSE
COPY --from=build /src/ggml/LICENSE /usr/share/doc/stable-diffusion.cpp/ggml-LICENSE
LABEL org.coreai.purpose="image-spike"
ENV HOME=/tmp PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
