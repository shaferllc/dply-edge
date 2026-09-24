module Dply
  module Rails
    class Railtie < ::Rails::Railtie
      # Ahead of the router and CSRF: the Worker authenticates with a token.
      initializer "dply.queue_middleware" do |app|
        app.middleware.insert_before 0, Dply::Rails::QueueMiddleware
      end

      initializer "dply.kv_cache" do |app|
        next if ENV.fetch("DPLY_KV_HOST", "").empty? || !ENV.fetch("REDIS_URL", "").empty?

        app.config.cache_store = :dply
      end
    end
  end
end
