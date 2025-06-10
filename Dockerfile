FROM php:8.1-cli

# Установка ffmpeg
RUN apt-get update && apt-get install -y ffmpeg

# Копируем все файлы в контейнер
COPY . /app
WORKDIR /app

# Открываем порт
EXPOSE 80

# Запускаем встроенный PHP-сервер (обслуживает любые .php файлы)
CMD ["php", "-S", "0.0.0.0:80"]

