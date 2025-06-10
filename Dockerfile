FROM php:8.2-cli

RUN apt-get update && apt-get install -y ffmpeg unzip

WORKDIR /app
COPY . /app

CMD ["php", "video.php"]
