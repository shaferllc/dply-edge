module Dply
  module Rails
    class Railtie < ::Rails::Railtie
      # Ahead of the router and CSRF: the Worker authenticates with a token.
      initializer "dply.queue_middleware" do |app|
        app.middleware.insert_before 0, Dply::Rails::QueueMiddleware
      end
    end
  end
end
