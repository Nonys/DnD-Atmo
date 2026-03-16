FROM php:8.2-cli

# System deps: build tools for whisper.cpp + ffmpeg for audio conversion
RUN apt-get update && apt-get install -y \
    git cmake make g++ ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Enable PHP curl extension
RUN docker-php-ext-install -j$(nproc) pcntl \
    && apt-get purge -y --auto-remove

# Build whisper.cpp from source (CPU-only, no GPU)
RUN git clone --depth 1 https://github.com/ggerganov/whisper.cpp /opt/whisper.cpp \
    && cd /opt/whisper.cpp \
    && cmake -B build -DGGML_METAL=OFF -DGGML_CUDA=OFF \
    && cmake --build build --config Release -j$(nproc) \
    && cp /opt/whisper.cpp/build/bin/whisper-cli /usr/local/bin/whisper-cli \
    && rm -rf /opt/whisper.cpp/.git

WORKDIR /app

COPY public/ /app/public/

RUN mkdir -p /app/public/sessions /app/models /app/data

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app/public"]
