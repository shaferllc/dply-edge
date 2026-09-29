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
        return @app.call(env) unless [RECEIVE_PATH, SCHEDULE_PATH, COMMAND_PATH].include?(env["PATH_INFO"]) && env["REQUEST_METHOD"] == "POST"

        token = Dply::Rails.token
        given = env["HTTP_X_DPLY_QUEUE_TOKEN"].to_s
        unless !token.empty? && Rack::Utils.secure_compare(token, given)
          return [403, { "content-type" => "application/json" }, ['{"error":"Forbidden"}']]
        end

        return run_task(JSON.parse(env["rack.input"].read)) if env["PATH_INFO"] == SCHEDULE_PATH
        return run_command(JSON.parse(env["rack.input"].read)) if env["PATH_INFO"] == COMMAND_PATH

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

      def run_command(payload)
        return list_tasks if payload["command"].to_s == "commands"

        task = COMMANDS[payload["command"].to_s]
        return [422, { "content-type" => "application/json" }, ['{"error":"Unknown command."}']] if task.nil?

        run_task("handler" => task)
      end

      # Cron Trigger: the handler is a rake task name, e.g. "reports:daily".
      # Anything that isn't a defined task runs as a shell command in the app
      # root, e.g. "bin/rails runner Cleanup.call" or "ruby scripts/sync.rb".
      def run_task(payload)
        task = payload["handler"].to_s.strip.sub(/\A(?:bin\/)?rake\s+/, "")
        return [422, { "content-type" => "application/json" }, ['{"error":"handler (rake task) required"}']] if task.empty?

        load_tasks
        return run_shell(task) unless Rake::Task.task_defined?(task)

        Rake::Task[task].reenable
        Rake::Task[task].invoke
        [200, { "content-type" => "application/json" }, [JSON.generate(task: task)]]
      rescue StandardError => e
        [500, { "content-type" => "application/json" }, [JSON.generate(task: task, error: e.message)]]
      end

      # Every described rake task, the app's own (lib/tasks) first, for the
      # scheduled-task picker.
      def list_tasks
        load_tasks
        own = ::Rails.root.join("lib/tasks").to_s
        tasks = Rake::Task.tasks.select(&:comment).map do |t|
          { name: t.name, description: t.comment.to_s, app: t.locations.any? { |l| l.start_with?(own) } }
        end
        tasks.sort_by! { |t| [t[:app] ? 0 : 1, t[:name]] }
        [200, { "content-type" => "application/json" }, [JSON.generate(commands: tasks)]]
      end

      # Once per process: Rake appends a task's actions each time its file
      # loads, so loading again would run every task twice. Metadata on so
      # `desc` comments and locations are kept for list_tasks.
      def load_tasks
        return if @tasks_loaded

        require "rake"
        Rake::TaskManager.record_task_metadata = true
        ::Rails.application.load_tasks
        @tasks_loaded = true
      end

      def run_shell(command)
        require "open3"
        output, status = Open3.capture2e(command, chdir: ::Rails.root.to_s)
        body = JSON.generate(command: command, exit: status.exitstatus, output: output[-2000..] || output)
        [status.success? ? 200 : 500, { "content-type" => "application/json" }, [body]]
      end
    end
  end
end
