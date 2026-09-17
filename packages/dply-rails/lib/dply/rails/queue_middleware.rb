require "rack"

module Dply
  module Rails
    # Runs a batch pushed by the site Worker. Answers {"failed": [ids]}; the
    # Worker retries those and acks the rest.
    class QueueMiddleware
      def initialize(app)
        @app = app
      end

      def call(env)
        return @app.call(env) unless env["PATH_INFO"] == RECEIVE_PATH && env["REQUEST_METHOD"] == "POST"

        token = Dply::Rails.token
        given = env["HTTP_X_DPLY_QUEUE_TOKEN"].to_s
        unless !token.empty? && Rack::Utils.secure_compare(token, given)
          return [403, { "content-type" => "application/json" }, ['{"error":"Forbidden"}']]
        end

        batch = JSON.parse(env["rack.input"].read)
        failed = Array(batch["messages"]).filter_map do |message|
          ActiveJob::Base.execute(message["body"])
          nil
        rescue StandardError => e
          warn "[dply] job #{message["id"]} failed: #{e.class}: #{e.message}"
          message["id"]
        end

        [200, { "content-type" => "application/json" }, [JSON.generate(failed: failed)]]
      end
    end
  end
end
