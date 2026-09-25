require "json"
require "net/http"
require "uri"

module Dply
  module Rails
    SEND_PATH = "/_dply/queue/send".freeze
    RECEIVE_PATH = "/_dply/queue".freeze
    SCHEDULE_PATH = "/_dply/schedule".freeze
    COMMAND_PATH = "/_dply/command".freeze
    COMMANDS = {
      "migrate" => "db:migrate",
      "status" => "db:migrate:status",
      "seed" => "db:seed",
      "rollback" => "db:rollback",
      "prepare" => "db:prepare",
    }.freeze

    def self.token
      ENV.fetch("DPLY_QUEUE_TOKEN", "")
    end

    def self.app_url
      ENV.fetch("DPLY_APP_URL", "").chomp("/")
    end

    def self.default_queue
      ENV.fetch("DPLY_QUEUE", "JOBS")
    end

    # Send a job payload to the site's Cloudflare Queue via its Worker.
    def self.publish(body, queue:, delay: 0)
      raise "dply: DPLY_APP_URL and DPLY_QUEUE_TOKEN must be set" if app_url.empty? || token.empty?

      uri = URI(app_url + SEND_PATH)
      request = Net::HTTP::Post.new(uri, "content-type" => "application/json", "x-dply-queue-token" => token)
      request.body = JSON.generate(queue: queue, body: body, delay: [delay.to_i, 0].max)
      response = Net::HTTP.start(uri.host, uri.port, use_ssl: uri.scheme == "https", open_timeout: 5, read_timeout: 10) { |http| http.request(request) }
      raise "dply: queue send failed (#{response.code})" unless response.is_a?(Net::HTTPSuccess)
    end
  end
end

require "dply/rails/storage"
require "dply/rails/kv"
require "dply/rails/queue_middleware"
require "active_job/queue_adapters/dply_adapter"
require "dply/rails/railtie" if defined?(::Rails::Railtie)
