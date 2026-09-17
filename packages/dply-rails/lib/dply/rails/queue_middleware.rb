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
        return @app.call(env) unless [RECEIVE_PATH, SCHEDULE_PATH].include?(env["PATH_INFO"]) && env["REQUEST_METHOD"] == "POST"

        token = Dply::Rails.token
        given = env["HTTP_X_DPLY_QUEUE_TOKEN"].to_s
        unless !token.empty? && Rack::Utils.secure_compare(token, given)
          return [403, { "content-type" => "application/json" }, ['{"error":"Forbidden"}']]
        end

        return run_task(JSON.parse(env["rack.input"].read)) if env["PATH_INFO"] == SCHEDULE_PATH

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

      private

      # Cron Trigger: the handler is a rake task name, e.g. "reports:daily".
      def run_task(payload)
        task = payload["handler"].to_s
        return [422, { "content-type" => "application/json" }, ['{"error":"handler (rake task) required"}']] if task.empty?

        require "rake"
        ::Rails.application.load_tasks unless Rake::Task.task_defined?(task)
        Rake::Task[task].reenable
        Rake::Task[task].invoke
        [200, { "content-type" => "application/json" }, [JSON.generate(task: task)]]
      rescue StandardError => e
        [500, { "content-type" => "application/json" }, [JSON.generate(task: task, error: e.message)]]
      end
    end
  end
end
