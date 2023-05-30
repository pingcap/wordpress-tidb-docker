# WordPress TiDB Docker

Use the TiDB Serverless Tier cluster as the database for WordPress.

## Prerequisites

- [TiDB Cloud Serverless Tier](https://tidbcloud.com/)
- [Docker](https://www.docker.com/)
- [Docker Compose](https://docs.docker.com/compose/) (If you have installed **Docker Desktop**, **Docker Compose** should already be included)

## Usage

- Run `mv .env.example .env`.
- Change the properties in `.env` to match your TiDB Serverless Tier cluster information.
- Run `docker compose up -d`.
- That's it! You can now visit the WordPress site by going to the [local page](http://localhost:8080).

## Stop & Clean

To stop and clean up the Docker containers, run `docker compose down`.
